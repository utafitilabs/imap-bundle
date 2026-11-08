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
use Symfony\Component\Clock\ClockInterface;
use UtafitiLabs\ImapBundle\Client\ImapClientInterface;
use UtafitiLabs\ImapBundle\Exception\ImapException;
use UtafitiLabs\ImapBundle\Exception\InvalidMailboxDsnException;
use UtafitiLabs\ImapBundle\Factory\ImapClientFactoryInterface;
use UtafitiLabs\ImapBundle\Model\Mailbox;

/**
 * Keeps a mailbox open and hands its mail to a handler as it arrives:
 * connect, EXAMINE, catch up on the last days, then IDLE (or check every poll
 * interval when the server has no IDLE) and fetch what is new by UID. Any
 * connection or protocol error closes the connection and reconnects after a
 * delay that doubles up to a cap. Only the headers of a message are fetched
 * until the handler wants it.
 *
 * Logs carry the mailbox name, UIDs, Message-IDs and what was decided; never
 * a DSN, a password, an address, a subject or a body.
 */
final class MailboxWatcherService
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ImapClientFactoryInterface $clients,
        private readonly MessageReaderService $reader,
        private readonly ClockInterface $clock,
        ?LoggerInterface $logger = null,
        private readonly int $maxReconnectDelay = 300,
    ) {
        $this->logger = $logger ?? new NullLogger();
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
        $uidValidity = null;
        $highestUid = 0;
        $pollingAnnounced = false;

        while (!$shouldStop()) {
            $client = $this->clients->create($mailbox->dsn, $mailbox->maxMessageBytes);
            try {
                $client->open();
                $status = $client->examine($mailbox->folder);
                if ($status->uidValidity !== $uidValidity) {
                    $uidValidity = $status->uidValidity;
                    $highestUid = 0;
                }
                $failures = 0;
                $this->logger->info('Watching mailbox {mailbox}.', ['mailbox' => $mailbox->name]);

                $since = $this->clock->now()->modify(\sprintf('-%d days', $mailbox->catchUpDays));
                $this->deliver($client, $mailbox, $handler, $uidValidity, $client->searchSince($since), $highestUid, $shouldStop);

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
                        $this->deliver($client, $mailbox, $handler, $uidValidity, $client->searchUidsFrom($highestUid + 1), $highestUid, $shouldStop);
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
     * Offers each message above $highestUid to the handler, raising
     * $highestUid past every message dealt with, so that after a dropped
     * connection the next one resumes where this one stopped.
     *
     * @param list<int>        $uids
     * @param \Closure(): bool $shouldStop
     *
     * @throws ImapException
     */
    private function deliver(ImapClientInterface $client, Mailbox $mailbox, IncomingMailHandlerInterface $handler, int $uidValidity, array $uids, int &$highestUid, \Closure $shouldStop): void
    {
        $uids = array_values(array_filter($uids, static fn (int $uid): bool => $uid > $highestUid));
        if ([] === $uids) {
            return;
        }

        foreach ($client->fetchHeaders($uids) as $raw) {
            if ($shouldStop()) {
                return;
            }
            $fallbackMessageId = \sprintf('<%d.%d@%s.imap.invalid>', $uidValidity, $raw->uid, preg_replace('/[^A-Za-z0-9.-]/', '-', $mailbox->name));
            $headers = $this->reader->readHeaders($raw->bytes, $fallbackMessageId);
            $context = ['mailbox' => $mailbox->name, 'uid' => $raw->uid, 'message_id' => $headers->messageId];

            if ($raw->size > $mailbox->maxMessageBytes) {
                $this->logger->warning('Skipped message {uid} ({message_id}) of {mailbox}: {size} bytes is over the limit of {limit}.', $context + ['size' => $raw->size, 'limit' => $mailbox->maxMessageBytes]);
                $highestUid = max($highestUid, $raw->uid);
                continue;
            }

            try {
                $wanted = $handler->wants($headers);
            } catch (\Throwable $exception) {
                $this->logger->error('The handler of {mailbox} failed on message {uid} ({message_id}).', $context + ['exception' => $exception]);
                $highestUid = max($highestUid, $raw->uid);
                continue;
            }
            if (!$wanted) {
                $this->logger->debug('Message {uid} ({message_id}) of {mailbox} declined by the handler.', $context);
                $highestUid = max($highestUid, $raw->uid);
                continue;
            }

            $fetched = $client->fetch($raw->uid);
            if (null === $fetched) {
                $this->logger->info('Message {uid} ({message_id}) of {mailbox} was gone before it was fetched.', $context);
                $highestUid = max($highestUid, $raw->uid);
                continue;
            }

            try {
                $handler->handle($this->reader->read($fetched->bytes, $fallbackMessageId, $fetched->internalDate));
                $this->logger->info('Message {uid} ({message_id}) of {mailbox} handled.', $context);
            } catch (\Throwable $exception) {
                $this->logger->error('The handler of {mailbox} failed on message {uid} ({message_id}).', $context + ['exception' => $exception]);
            }
            $highestUid = max($highestUid, $raw->uid);
        }

        $highestUid = max($highestUid, ...$uids);
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
