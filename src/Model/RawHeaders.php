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
 * The selected header fields of one message as the server sent them, with the
 * message's full size in bytes (RFC822.SIZE).
 */
final readonly class RawHeaders
{
    public function __construct(
        public int $uid,
        public int $size,
        public string $bytes,
    ) {
    }
}
