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

namespace UtafitiLabs\ImapBundle\Tests\Unit\Factory;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use UtafitiLabs\ImapBundle\Client\ImapClient;
use UtafitiLabs\ImapBundle\Factory\ImapClientFactory;

final class ImapClientFactoryTest extends TestCase
{
    public function testItMakesAClient(): void
    {
        self::assertInstanceOf(ImapClient::class, (new ImapClientFactory(new MockClock()))->create('imaps://u:p@imap.example.com', 1024));
    }
}
