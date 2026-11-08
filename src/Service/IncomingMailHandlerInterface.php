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

namespace UtafitiLabs\ImapBundle\Service;

use UtafitiLabs\ImapBundle\Model\Message;
use UtafitiLabs\ImapBundle\Model\MessageHeaders;

/**
 * What an application implements to receive the mail of a watched mailbox.
 *
 * The watcher first fetches only each message's identifying headers and asks
 * wants(); only a message the handler wants is fetched whole and passed to
 * handle(). wants() is also where a handler declines a message it has already
 * handled (by its Message-ID), since the watcher offers the last days' mail
 * again on every (re)connect. An application that takes every message returns
 * true. An exception from either method is logged and the watcher goes on with
 * the next message.
 */
interface IncomingMailHandlerInterface
{
    public function wants(MessageHeaders $headers): bool;

    public function handle(Message $message): void;
}
