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
 * How far a mailbox has been read: the folder's UIDVALIDITY and the highest
 * UID offered to the handler under it. A UID is only meaningful together with
 * its UIDVALIDITY (RFC 3501 §2.3.1.1); a client keeps the pair to resynchronise
 * after a restart (RFC 4549 §4.3).
 */
final readonly class MailboxState
{
    public function __construct(
        public int $uidValidity,
        public int $lastUid,
    ) {
    }
}
