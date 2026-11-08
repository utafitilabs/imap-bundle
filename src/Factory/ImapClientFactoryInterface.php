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

namespace UtafitiLabs\ImapBundle\Factory;

use UtafitiLabs\ImapBundle\Client\ImapClientInterface;
use UtafitiLabs\ImapBundle\Exception\InvalidMailboxDsnException;

/**
 * Makes a fresh, unopened client for each connection the watcher opens.
 */
interface ImapClientFactoryInterface
{
    /**
     * @throws InvalidMailboxDsnException
     */
    public function create(#[\SensitiveParameter] string $dsn, int $maxMessageBytes): ImapClientInterface;
}
