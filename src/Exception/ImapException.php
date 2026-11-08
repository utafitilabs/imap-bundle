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
 * Base of every failure talking to an IMAP server; the watcher answers any of
 * them by dropping the connection and reconnecting. A message never carries a
 * password, a DSN or a command line.
 */
abstract class ImapException extends \RuntimeException
{
}
