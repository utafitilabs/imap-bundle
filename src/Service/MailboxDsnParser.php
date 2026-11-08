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

use UtafitiLabs\ImapBundle\Exception\InvalidMailboxDsnException;
use UtafitiLabs\ImapBundle\Model\MailboxDsn;

/**
 * Reads a mailbox DSN: imaps://user:password@host[:993] for TLS from the first
 * byte, or imap://user:password@host[:143] for STARTTLS. User and password are
 * URL-encoded. An error never repeats the DSN, which carries the password.
 */
final class MailboxDsnParser
{
    private const array SCHEMES = ['imaps' => [993, true], 'imap' => [143, false]];

    /**
     * @throws InvalidMailboxDsnException
     */
    public function parse(#[\SensitiveParameter] string $dsn): MailboxDsn
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
