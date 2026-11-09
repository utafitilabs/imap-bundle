<?php

declare(strict_types=1);

/*
 * This file is part of the UtafitiLabs IMAP Bundle.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace UtafitiLabs\ImapBundle\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;
use UtafitiLabs\ImapBundle\Client\ImapClientInterface;
use UtafitiLabs\ImapBundle\Exception\ImapException;
use UtafitiLabs\ImapBundle\Exception\InvalidMailboxDsnException;
use UtafitiLabs\ImapBundle\Factory\ImapClientFactoryInterface;
use UtafitiLabs\ImapBundle\Model\Mailbox;
use UtafitiLabs\ImapBundle\Model\MailboxState;
use UtafitiLabs\ImapBundle\Model\RawHeaders;
use UtafitiLabs\ImapBundle\Store\CacheMailboxStateStore;
use UtafitiLabs\ImapBundle\Store\MailboxStateStoreInterface;

/**
 * Keeps a mailbox open and hands its mail to a handler as it arrives:
 * connect, EXAMINE, resume after the last UID read (or, on a first run, catch
 * up on the last days), then IDLE (or check every poll interval when the
 * server has no IDLE) and fetch what is new by UID. Any connection or
 * protocol error closes the connection and reconnects after a delay that
 * doubles up to a cap. Only the headers of a message are fetched until the
 * handler wants it.
 *
 * How far a mailbox has been read is kept in a MailboxStateStoreInterface as
 * the folder's UIDVALIDITY and the highest UID offered, the way a
 * disconnected IMAP client resynchronises (RFC 4549 §4.3). The state moves
 * past each message once the handler has dealt with it — handled, declined,
 * failed, too large or gone — so no message is offered twice; only a message
 * cut off by a lost connection is offered again.
 *
 * Logs carry the mailbox name, UIDs, Message-IDs and what was decided; never
 * a DSN, a password, an address, a subject or a body.
 */
final class MailboxWatcherService
{
    private readonly LoggerInterface $logger;
    private readonly MailboxStateStoreInterface $states;

    /**
     * Without a state store the state lives in memory and a restart catches
     * up on the last days again.
     */
    public function __construct(
        private readonly ImapClientFactoryInterface $clients,
        private readonly MessageReaderService $reader,
        private readonly ClockInterface $clock,
        ?LoggerInterface $logger = null,
        private readonly int $maxReconnectDelay = 300,
        ?MailboxStateStoreInterface $states = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->states = $states ?? new CacheMailboxStateStore(new ArrayAdapter(0, false));
    }

    /**
     * Watches until $shouldStop returns true; it is asked at least once a
     * second, so a stop request ends the watch within about a second.
     *
     * @param \Closure(): bool $shouldStop
     *
     * @throws InvalidMailboxDsnException
     */
    public function watch(Mailbox $mailbox, IncomingMailHandlerInterface $handler, \Closure $shouldStop): void
    {
        $failures = 0;
        $pollingAnnounced = false;
        $state = null;

        while (!$shouldStop()) {
            $client = $this->clients->create($mailbox->dsn, $mailbox->maxMessageBytes);
            try {
                $client->open();
                $status = $client->examine($mailbox->folder);
                $failures = 0;
                $this->logger->info('Watching mailbox {mailbox}.', ['mailbox' => $mailbox->name]);

                $state = $this->states->get($mailbox) ?? $state;
                if (null !== $state && $state->uidValidity === $status->uidValidity) {
                    $this->logger->info('Resuming mailbox {mailbox} after UID {uid}.', ['mailbox' => $mailbox->name, 'uid' => $state->lastUid]);
                    $state = $this->deliver($client, $mailbox, $handler, $state, $client->searchUidsFrom($state->lastUid + 1), $shouldStop);
                } else {
                    if (null !== $state) {
                        $this->logger->info('The UIDVALIDITY of mailbox {mailbox} changed; catching up on the last {days} days.', ['mailbox' => $mailbox->name, 'days' => $mailbox->catchUpDays]);
                    }
                    $state = new MailboxState($status->uidValidity, 0);
                    $since = $this->clock->now()->modify(\sprintf('-%d days', $mailbox->catchUpDays));
                    $state = $this->deliver($client, $mailbox, $handler, $state, $client->searchSince($since), $shouldStop, ($status->uidNext ?? 1) - 1);
                }

                while (!$shouldStop()) {
                    if ($client->supportsIdle()) {
                        $arrived = $client->idle($mailbox->idleSeconds, $shouldStop);
                    } else {
                        if (!$pollingAnnounced) {
                            $pollingAnnounced = true;
                            $this->logger->info('The server of {mailbox} has no IDLE; checking every {seconds} s.', ['mailbox' => $mailbox->name, 'seconds' => $mailbox->pollSeconds]);
                        }
                        if ($this->sleep($mailbox->pollSeconds, $shouldStop)) {
                            break;
                        }
                        $client->noop();
                        $arrived = true;
                    }
                    if ($arrived) {
                        $state = $this->deliver($client, $mailbox, $handler, $state, $client->searchUidsFrom($state->lastUid + 1), $shouldStop);
                    }
                }

                $client->logout();

                return;
            } catch (ImapException $exception) {
                $client->close();
                $delay = min($this->maxReconnectDelay, 2 ** min($failures, 30));
                ++$failures;
                $this->logger->warning('Lost mailbox {mailbox}: {reason}; reconnecting in {seconds} s.', [
                    'mailbox' => $mailbox->name,
                    'reason' => $exception->getMessage(),
                    'seconds' => $delay,
                ]);
                $this->sleep($delay, $shouldStop);
            }
        }
    }

    /**
     * Offers each message above the state's last UID to the handler and saves
     * the state past each one dealt with. When all are dealt with, the state
     * moves on to $through as well: after a catch-up, the newest UID the folder
     * had, so that mail older than the window is never offered later.
     * Returns the state reached.
     *
     * @phpstan-impure
     *
     * @param list<int>        $uids
     * @param \Closure(): bool $shouldStop
     *
     * @throws ImapException
     */
    private function deliver(ImapClientInterface $client, Mailbox $mailbox, IncomingMailHandlerInterface $handler, MailboxState $state, array $uids, \Closure $shouldStop, int $through = 0): MailboxState
    {
        $uids = array_values(array_filter($uids, static fn (int $uid): bool => $uid > $state->lastUid));
        if ([] === $uids) {
            return $this->advance($mailbox, $state, $through);
        }

        foreach ($client->fetchHeaders($uids) as $raw) {
            if ($shouldStop()) {
                return $state;
            }
            $this->offer($client, $mailbox, $handler, $state->uidValidity, $raw);
            $state = $this->advance($mailbox, $state, $raw->uid);
        }

        return $this->advance($mailbox, $state, max($through, ...$uids));
    }

    /**
     * @throws ImapException
     */
    private function offer(ImapClientInterface $client, Mailbox $mailbox, IncomingMailHandlerInterface $handler, int $uidValidity, RawHeaders $raw): void
    {
        $fallbackMessageId = \sprintf('<%d.%d@%s.imap.invalid>', $uidValidity, $raw->uid, preg_replace('/[^A-Za-z0-9.-]/', '-', $mailbox->name));
        $headers = $this->reader->readHeaders($raw->bytes, $fallbackMessageId);
        $context = ['mailbox' => $mailbox->name, 'uid' => $raw->uid, 'message_id' => $headers->messageId];

        if ($raw->size > $mailbox->maxMessageBytes) {
            $this->logger->warning('Skipped message {uid} ({message_id}) of {mailbox}: {size} bytes is over the limit of {limit}.', $context + ['size' => $raw->size, 'limit' => $mailbox->maxMessageBytes]);

            return;
        }

        try {
            $wanted = $handler->wants($headers);
        } catch (\Throwable $exception) {
            $this->logger->error('The handler of {mailbox} failed on message {uid} ({message_id}).', $context + ['exception' => $exception]);

            return;
        }
        if (!$wanted) {
            $this->logger->debug('Message {uid} ({message_id}) of {mailbox} declined by the handler.', $context);

            return;
        }

        $fetched = $client->fetch($raw->uid);
        if (null === $fetched) {
            $this->logger->info('Message {uid} ({message_id}) of {mailbox} was gone before it was fetched.', $context);

            return;
        }

        try {
            $handler->handle($this->reader->read($fetched->bytes, $fallbackMessageId, $fetched->internalDate));
            $this->logger->info('Message {uid} ({message_id}) of {mailbox} handled.', $context);
        } catch (\Throwable $exception) {
            $this->logger->error('The handler of {mailbox} failed on message {uid} ({message_id}).', $context + ['exception' => $exception]);
        }
    }

    /**
     * Saves the state with $uid as its last UID when that is further on.
     */
    private function advance(Mailbox $mailbox, MailboxState $state, int $uid): MailboxState
    {
        if ($uid <= $state->lastUid) {
            return $state;
        }
        $state = new MailboxState($state->uidValidity, $uid);
        $this->states->save($mailbox, $state);

        return $state;
    }

    /**
     * Sleeps up to $seconds in steps of a second; true when a stop cut it short.
     *
     * @param \Closure(): bool $shouldStop
     */
    private function sleep(int $seconds, \Closure $shouldStop): bool
    {
        for ($slept = 0; $slept < $seconds; ++$slept) {
            if ($shouldStop()) {
                return true;
            }
            $this->clock->sleep(1);
        }

        return $shouldStop();
    }
}
