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

namespace UtafitiLabs\ImapBundle\Client;

use Psr\Clock\ClockInterface;
use UtafitiLabs\ImapBundle\Exception\ImapCommandFailedException;
use UtafitiLabs\ImapBundle\Exception\ImapConnectionException;
use UtafitiLabs\ImapBundle\Exception\ImapException;
use UtafitiLabs\ImapBundle\Exception\ImapProtocolException;
use UtafitiLabs\ImapBundle\Model\MailboxDsn;
use UtafitiLabs\ImapBundle\Model\MailboxStatus;
use UtafitiLabs\ImapBundle\Model\RawHeaders;
use UtafitiLabs\ImapBundle\Model\RawMessage;
use UtafitiLabs\ImapBundle\Model\ResponseLine;

/**
 * The read-only IMAP client (RFC 9051, IDLE per RFC 2177) over a transport.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9051
 * @see https://www.rfc-editor.org/rfc/rfc2177
 */
final class ImapClient implements ImapClientInterface
{
    public const string HEADER_FIELDS = 'MESSAGE-ID FROM TO CC SUBJECT DATE IN-REPLY-TO REFERENCES AUTHENTICATION-RESULTS';

    private const float CONNECT_TIMEOUT = 30.0;
    private const int UIDS_PER_FETCH = 200;

    private readonly ImapConnection $connection;

    /** @var list<AuthenticatorInterface> */
    private readonly array $authenticators;

    /**
     * @param list<AuthenticatorInterface>|null $authenticators tried in order; PLAIN, then LOGIN when null
     */
    public function __construct(
        TransportInterface $transport,
        private readonly MailboxDsn $dsn,
        private readonly ClockInterface $clock,
        int $maxLiteralBytes,
        float $commandTimeout = 60.0,
        ?array $authenticators = null,
    ) {
        $this->connection = new ImapConnection($transport, $maxLiteralBytes, $commandTimeout);
        $this->authenticators = $authenticators ?? [new PlainAuthenticator(), new LoginAuthenticator()];
    }

    public function open(): void
    {
        $this->connection->open($this->dsn->host, $this->dsn->port, $this->dsn->implicitTls, self::CONNECT_TIMEOUT);
        if (!$this->connection->knowsCapabilities()) {
            $this->connection->command('CAPABILITY');
        }
        if (!$this->dsn->implicitTls) {
            if (!$this->connection->hasCapability('STARTTLS')) {
                throw new ImapConnectionException('The server does not offer STARTTLS; refusing to send credentials over an unencrypted connection.');
            }
            $this->connection->startTls();
        }

        foreach ($this->authenticators as $authenticator) {
            if ($authenticator->supports($this->connection)) {
                $announcements = $this->connection->capabilityAnnouncements();
                $authenticator->authenticate($this->connection, $this->dsn);
                if ($announcements === $this->connection->capabilityAnnouncements()) {
                    $this->connection->command('CAPABILITY');
                }

                return;
            }
        }

        throw new ImapConnectionException('The server offers no authentication mechanism this client supports.');
    }

    public function supportsIdle(): bool
    {
        return $this->connection->hasCapability('IDLE');
    }

    public function examine(string $folder): MailboxStatus
    {
        $result = $this->connection->command('EXAMINE', $this->connection->quote(
            'INBOX' === strtoupper($folder) ? 'INBOX' : mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8'),
        ));
        $this->connection->takeExistsAnnouncement();

        $uidValidity = 0;
        $exists = 0;
        $uidNext = null;
        foreach ($result->untagged as $line) {
            if (1 === preg_match('/^(\d+) EXISTS$/i', $line->text, $match)) {
                $exists = (int) $match[1];
            } elseif (1 === preg_match('/^OK \[UIDVALIDITY (\d+)\]/i', $line->text, $match)) {
                $uidValidity = (int) $match[1];
            } elseif (1 === preg_match('/^OK \[UIDNEXT (\d+)\]/i', $line->text, $match)) {
                $uidNext = (int) $match[1];
            }
        }

        return new MailboxStatus($uidValidity, $exists, $uidNext);
    }

    public function searchSince(\DateTimeImmutable $since): array
    {
        return $this->search('SINCE '.$since->format('j-M-Y'));
    }

    public function searchUidsFrom(int $uid): array
    {
        return array_values(array_filter($this->search('UID '.$uid.':*'), static fn (int $found): bool => $found >= $uid));
    }

    public function fetchHeaders(array $uids): array
    {
        $headers = [];
        foreach (array_chunk($uids, self::UIDS_PER_FETCH) as $chunk) {
            $result = $this->connection->command('UID FETCH', implode(',', $chunk).' (UID RFC822.SIZE BODY.PEEK[HEADER.FIELDS ('.self::HEADER_FIELDS.')])');
            foreach ($result->untagged as $line) {
                $uid = $this->fetchedUid($line);
                $bytes = null === $uid || !\in_array($uid, $chunk, true) ? null : $this->fetchedBody($line);
                if (null !== $uid && null !== $bytes) {
                    $headers[$uid] = new RawHeaders($uid, $this->fetchedSize($line), $bytes);
                }
            }
        }
        ksort($headers);

        return array_values($headers);
    }

    public function fetch(int $uid): ?RawMessage
    {
        $result = $this->connection->command('UID FETCH', $uid.' (UID RFC822.SIZE INTERNALDATE BODY.PEEK[])');
        foreach ($result->untagged as $line) {
            $bytes = $uid === $this->fetchedUid($line) ? $this->fetchedBody($line) : null;
            if (null === $bytes) {
                continue;
            }
            $internalDate = null;
            if (1 === preg_match('/INTERNALDATE "([^"]+)"/i', $line->text, $match)) {
                $internalDate = \DateTimeImmutable::createFromFormat('j-M-Y H:i:s O', trim($match[1])) ?: null;
            }

            return new RawMessage($uid, $this->fetchedSize($line), $internalDate, $bytes);
        }

        return null;
    }

    public function idle(int $seconds, \Closure $shouldStop): bool
    {
        if ($this->connection->takeExistsAnnouncement()) {
            return true;
        }
        if (!$this->supportsIdle()) {
            throw new ImapProtocolException('The server does not support IDLE.');
        }

        $tag = $this->connection->send('IDLE');
        while (true) {
            $line = $this->connection->next(60.0) ?? throw new ImapConnectionException('IDLE timed out.');
            if ('+' === $line->tag) {
                break;
            }
            if ($tag === $line->tag) {
                throw new ImapCommandFailedException(\sprintf('IDLE failed: %s', $line->text));
            }
        }

        $deadline = $this->seconds() + $seconds;
        $announced = false;
        while (!$announced && !$shouldStop()) {
            $remaining = $deadline - $this->seconds();
            if ($remaining <= 0) {
                break;
            }
            $this->connection->next(min(1.0, $remaining));
            $announced = $this->connection->takeExistsAnnouncement();
        }

        $this->connection->write("DONE\r\n");
        $this->connection->complete($tag, 'IDLE');

        return $this->connection->takeExistsAnnouncement() || $announced;
    }

    public function noop(): void
    {
        $this->connection->command('NOOP');
    }

    public function logout(): void
    {
        try {
            $this->connection->command('LOGOUT');
        } catch (ImapException) {
        } finally {
            $this->connection->close();
        }
    }

    public function close(): void
    {
        $this->connection->close();
    }

    /**
     * @return list<int>
     */
    private function search(string $criteria): array
    {
        $uids = [];
        foreach ($this->connection->command('UID SEARCH', $criteria)->untagged as $line) {
            if (1 === preg_match('/^SEARCH((?: \d+)*)\s*$/i', $line->text, $match)) {
                foreach (explode(' ', trim($match[1])) as $uid) {
                    if ('' !== $uid) {
                        $uids[(int) $uid] = (int) $uid;
                    }
                }
            }
        }
        ksort($uids);

        return array_values($uids);
    }

    private function fetchedUid(ResponseLine $line): ?int
    {
        if (1 !== preg_match('/^\d+ FETCH \(/i', $line->text) || 1 !== preg_match('/\bUID (\d+)/i', $line->text, $match)) {
            return null;
        }

        return (int) $match[1];
    }

    private function fetchedSize(ResponseLine $line): int
    {
        return 1 === preg_match('/RFC822\.SIZE (\d+)/i', $line->text, $match) ? (int) $match[1] : 0;
    }

    /**
     * The BODY[...] item of a FETCH response: a literal, a quoted string, or NIL.
     */
    private function fetchedBody(ResponseLine $line): ?string
    {
        if (1 !== preg_match('/BODY\[[^\]]*\] /i', $line->text, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $offset = $match[0][1] + \strlen($match[0][0]);
        $rest = substr($line->text, $offset);
        if (1 === preg_match('/^\{\d+\}/', $rest)) {
            $index = preg_match_all('/\{\d+\}/', substr($line->text, 0, $offset));

            return $line->literals[$index] ?? null;
        }
        if (1 === preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $rest, $quoted)) {
            return (string) preg_replace('/\\\\(.)/', '$1', $quoted[1]);
        }

        return '';
    }

    private function seconds(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
