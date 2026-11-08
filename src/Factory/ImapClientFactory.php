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

use Psr\Clock\ClockInterface;
use UtafitiLabs\ImapBundle\Client\ImapClient;
use UtafitiLabs\ImapBundle\Client\ImapClientInterface;
use UtafitiLabs\ImapBundle\Client\StreamSocketTransport;
use UtafitiLabs\ImapBundle\Service\MailboxDsnParser;

/**
 * Makes clients over the TLS socket transport from a mailbox DSN, read by
 * {@see MailboxDsnParser}.
 */
final class ImapClientFactory implements ImapClientFactoryInterface
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly MailboxDsnParser $dsns = new MailboxDsnParser(),
        private readonly ?string $caFile = null,
        private readonly float $commandTimeout = 60.0,
    ) {
    }

    public function create(#[\SensitiveParameter] string $dsn, int $maxMessageBytes): ImapClientInterface
    {
        return new ImapClient(
            new StreamSocketTransport($this->caFile),
            $this->dsns->parse($dsn),
            $this->clock,
            $maxMessageBytes + 65536,
            $this->commandTimeout,
        );
    }
}
