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
 * A completed command: its tagged OK and the untagged responses that came
 * before it.
 */
final readonly class CommandResult
{
    /**
     * @param list<ResponseLine> $untagged
     */
    public function __construct(
        public ResponseLine $completion,
        public array $untagged,
    ) {
    }
}
