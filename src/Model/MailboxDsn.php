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

namespace UtafitiLabs\ImapBundle\Model;

/**
 * Where and as whom to log in. $implicitTls is true for imaps:// (TLS from the
 * first byte) and false for imap:// (STARTTLS, required before any credential).
 */
final readonly class MailboxDsn
{
    public function __construct(
        public string $host,
        public int $port,
        public bool $implicitTls,
        public string $user,
        #[\SensitiveParameter]
        public string $password,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'implicitTls' => $this->implicitTls, 'user' => $this->user, 'password' => '*****'];
    }
}
