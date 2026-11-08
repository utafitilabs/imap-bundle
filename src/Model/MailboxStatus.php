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
 * What EXAMINE reported about a folder.
 */
final readonly class MailboxStatus
{
    public function __construct(
        public int $uidValidity,
        public int $exists,
        public ?int $uidNext,
    ) {
    }
}
