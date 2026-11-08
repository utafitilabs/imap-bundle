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

namespace UtafitiLabs\ImapBundle\Factory;

use Psr\Clock\ClockInterface;
use UtafitiLabs\ImapBundle\Client\ImapClient;
use UtafitiLabs\ImapBundle\Client\ImapClientInterface;
use UtafitiLabs\ImapBundle\Client\StreamSocketTransport;
use UtafitiLabs\ImapBundle\Exception\InvalidMailboxDsnException;
use UtafitiLabs\ImapBundle\Model\MailboxDsn;

/**
 * Makes clients over the TLS socket transport from a mailbox DSN:
 * imaps://user:password@host[:993] for TLS from the first byte, or
 * imap://user:password@host[:143] for STARTTLS. User and password are
 * URL-encoded.
 */
final class ImapClientFactory implements ImapClientFactoryInterface
{
    private const array SCHEMES = ['imaps' => [993, true], 'imap' => [143, false]];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly ?string $caFile = null,
        private readonly float $commandTimeout = 60.0,
    ) {
    }

    public function create(#[\SensitiveParameter] string $dsn, int $maxMessageBytes): ImapClientInterface
    {
        return new ImapClient(
            new StreamSocketTransport($this->caFile),
            $this->parseDsn($dsn),
            $this->clock,
            $maxMessageBytes + 65536,
            $this->commandTimeout,
        );
    }

    /**
     * @throws InvalidMailboxDsnException
     */
    public function parseDsn(#[\SensitiveParameter] string $dsn): MailboxDsn
    {
        $parts = parse_url($dsn);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!isset(self::SCHEMES[$scheme])) {
            throw new InvalidMailboxDsnException('A mailbox DSN starts with imaps:// or imap://.');
        }
        if ('' === ($parts['host'] ?? '')) {
            throw new InvalidMailboxDsnException('The mailbox DSN names no host.');
        }
        $user = rawurldecode($parts['user'] ?? '');
        if ('' === $user) {
            throw new InvalidMailboxDsnException('The mailbox DSN names no user.');
        }
        [$defaultPort, $implicitTls] = self::SCHEMES[$scheme];

        return new MailboxDsn($parts['host'], $parts['port'] ?? $defaultPort, $implicitTls, $user, rawurldecode($parts['pass'] ?? ''));
    }
}
