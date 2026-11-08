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
 * One message's RFC 822 bytes and the date the server received it.
 */
final readonly class RawMessage
{
    public function __construct(
        public int $uid,
        public int $size,
        public ?\DateTimeImmutable $internalDate,
        public string $bytes,
    ) {
    }
}
