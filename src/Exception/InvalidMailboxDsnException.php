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
 * A mailbox DSN that cannot be read; the message never repeats the DSN.
 */
final class InvalidMailboxDsnException extends \InvalidArgumentException
{
}
