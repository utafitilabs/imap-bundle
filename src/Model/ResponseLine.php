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
 * One server response: its tag ("*" untagged, "+" continuation, or the
 * command's tag), the text after the tag with each literal's "{n}" marker left
 * in place, and the literals' bytes in order.
 */
final readonly class ResponseLine
{
    /**
     * @param list<string> $literals
     */
    public function __construct(
        public string $tag,
        public string $text,
        public array $literals = [],
    ) {
    }
}
