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

namespace UtafitiLabs\ImapBundle\Tests\Double;

use UtafitiLabs\ImapBundle\Client\ImapClientInterface;
use UtafitiLabs\ImapBundle\Factory\ImapClientFactoryInterface;

/**
 * Hands out the given clients, one per connection.
 */
final class FakeImapClientFactory implements ImapClientFactoryInterface
{
    /** @var list<string> */
    public array $dsns = [];

    /**
     * @param list<FakeImapClient> $clients
     */
    public function __construct(private array $clients)
    {
    }

    public function create(#[\SensitiveParameter] string $dsn, int $maxMessageBytes): ImapClientInterface
    {
        $this->dsns[] = $dsn;

        return array_shift($this->clients) ?? throw new \LogicException('No client left.');
    }
}
