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
 * The server sent something the client cannot or will not read, such as a
 * literal over the size cap or a response it does not understand.
 */
final class ImapProtocolException extends ImapException
{
}
