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
 * One mailbox of an address header: the address and, when given, the display
 * name, decoded to UTF-8.
 */
final readonly class Address
{
    public function __construct(
        public string $email,
        public ?string $name = null,
    ) {
    }
}
