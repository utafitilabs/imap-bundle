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

namespace UtafitiLabs\ImapBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UtafitiLabs\ImapBundle\Exception\InvalidMailboxDsnException;
use UtafitiLabs\ImapBundle\Model\MailboxDsn;
use UtafitiLabs\ImapBundle\Service\MailboxDsnParser;

final class MailboxDsnParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, MailboxDsn}>
     */
    public static function dsns(): iterable
    {
        yield 'imaps on its default port' => [
            'imaps://answers%40example.org:p%40ss%3Aw%2Frd@imap.example.com',
            new MailboxDsn('imap.example.com', 993, true, 'answers@example.org', 'p@ss:w/rd'),
        ];
        yield 'imaps on another port' => ['imaps://u:p@imap.example.com:1993', new MailboxDsn('imap.example.com', 1993, true, 'u', 'p')];
        yield 'imap with STARTTLS' => ['imap://u:p@imap.example.com', new MailboxDsn('imap.example.com', 143, false, 'u', 'p')];
        yield 'empty password' => ['imaps://u@imap.example.com', new MailboxDsn('imap.example.com', 993, true, 'u', '')];
    }

    #[DataProvider('dsns')]
    public function testItReadsADsn(string $dsn, MailboxDsn $expected): void
    {
        self::assertEquals($expected, (new MailboxDsnParser())->parse($dsn));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDsns(): iterable
    {
        yield 'other scheme' => ['pop3s://u:hunter2@pop.example.com'];
        yield 'no host' => ['imaps://u:hunter2@'];
        yield 'no user' => ['imaps://:hunter2@imap.example.com'];
        yield 'not a URL' => ['hunter2'];
    }

    #[DataProvider('invalidDsns')]
    public function testItRefusesAnInvalidDsnWithoutRepeatingIt(string $dsn): void
    {
        try {
            (new MailboxDsnParser())->parse($dsn);
            self::fail('The DSN must be refused.');
        } catch (InvalidMailboxDsnException $exception) {
            self::assertStringNotContainsString('hunter2', $exception->getMessage());
        }
    }

    public function testTheDsnHidesItsPasswordFromDumps(): void
    {
        self::assertStringNotContainsString('hunter2', print_r(new MailboxDsn('imap.example.com', 993, true, 'u', 'hunter2'), true));
    }
}
