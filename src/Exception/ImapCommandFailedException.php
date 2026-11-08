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

namespace UtafitiLabs\ImapBundle\Exception;

/**
 * The server answered a command with NO or BAD. The message names the command
 * verb and the server's answer, never the command's arguments.
 */
final class ImapCommandFailedException extends ImapException
{
}
