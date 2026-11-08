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
 * One configured mailbox. An empty $dsn means the mailbox is off.
 */
final readonly class Mailbox
{
    public function __construct(
        public string $name,
        #[\SensitiveParameter]
        public string $dsn,
        public string $folder = 'INBOX',
        public int $catchUpDays = 3,
        public int $idleSeconds = 1740,
        public int $pollSeconds = 300,
        public int $maxMessageBytes = 10485760,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'dsn' => '*****', 'folder' => $this->folder, 'catchUpDays' => $this->catchUpDays, 'idleSeconds' => $this->idleSeconds, 'pollSeconds' => $this->pollSeconds, 'maxMessageBytes' => $this->maxMessageBytes];
    }
}
