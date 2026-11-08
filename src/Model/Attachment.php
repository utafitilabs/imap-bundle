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
 * What is known about an attachment without decoding it: its file name, its
 * content type, and its decoded size in bytes (exact for base64, 7bit, 8bit
 * and binary parts).
 */
final readonly class Attachment
{
    public function __construct(
        public ?string $filename,
        public string $contentType,
        public int $size,
    ) {
    }
}
