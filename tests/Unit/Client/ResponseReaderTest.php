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

namespace UtafitiLabs\ImapBundle\Tests\Unit\Client;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use UtafitiLabs\ImapBundle\Client\ResponseReader;
use UtafitiLabs\ImapBundle\Exception\ImapConnectionException;
use UtafitiLabs\ImapBundle\Exception\ImapProtocolException;
use UtafitiLabs\ImapBundle\Model\ResponseLine;
use UtafitiLabs\ImapBundle\Tests\Double\ScriptedTransport;

final class ResponseReaderTest extends TestCase
{
    public function testItSplitsATaggedResponseIntoTagAndText(): void
    {
        $line = $this->reader("A0001 OK [READ-ONLY] Examine completed.\r\n")->next(1.0);

        self::assertNotNull($line);
        self::assertSame('A0001', $line->tag);
        self::assertSame('OK [READ-ONLY] Examine completed.', $line->text);
        self::assertSame([], $line->literals);
    }

    public function testItReadsUntaggedAndContinuationResponses(): void
    {
        $reader = $this->reader("* 3 EXISTS\r\n+ idling\r\n");

        self::assertSame(['*', '3 EXISTS'], $this->pair($reader->next(1.0)));
        self::assertSame(['+', 'idling'], $this->pair($reader->next(1.0)));
    }

    public function testItReadsALiteralByItsByteCountEvenWhenItHoldsLineBreaks(): void
    {
        $body = "Subject: Hi\r\n\r\nLine one\r\n) not the end\r\n";
        $line = $this->reader('* 1 FETCH (UID 7 BODY[] {'.\strlen($body)."}\r\n".$body.")\r\n")->next(1.0);

        self::assertNotNull($line);
        self::assertSame('1 FETCH (UID 7 BODY[] {'.\strlen($body).'})', $line->text);
        self::assertSame([$body], $line->literals);
    }

    public function testItReadsSeveralLiteralsAndAnEmptyOneInOneResponse(): void
    {
        $line = $this->reader("* 2 FETCH (BODY[1] {3}\r\nabc BODY[2] {0}\r\n BODY[3] {2}\r\nxy)\r\n")->next(1.0);

        self::assertNotNull($line);
        self::assertSame(['abc', '', 'xy'], $line->literals);
        self::assertSame('2 FETCH (BODY[1] {3} BODY[2] {0} BODY[3] {2})', $line->text);
    }

    public function testItRefusesALiteralOverTheCapWithoutReadingIt(): void
    {
        $this->expectException(ImapProtocolException::class);
        $this->expectExceptionMessage('literal of 2048 bytes');

        $this->reader("* 1 FETCH (BODY[] {2048}\r\n".str_repeat('x', 2048).")\r\n", 1024)->next(1.0);
    }

    public function testASilentServerYieldsNoResponseOnceTheTimeoutPasses(): void
    {
        $clock = new MockClock('2026-11-08 10:00:00');
        $reader = new ResponseReader(new ScriptedTransport($clock, [['C', "A0001 NOOP\r\n"]]), 1024);

        self::assertNull($reader->next(1.5));
        self::assertSame('2026-11-08 10:00:01.500000', $clock->now()->format('Y-m-d H:i:s.u'));
    }

    public function testAClosedConnectionIsAConnectionError(): void
    {
        $this->expectException(ImapConnectionException::class);

        (new ResponseReader(new ScriptedTransport(new MockClock(), [['EOF']]), 1024))->next(1.0);
    }

    private function reader(string $serverBytes, int $maxLiteralBytes = 1048576): ResponseReader
    {
        return new ResponseReader(new ScriptedTransport(new MockClock(), [['S', $serverBytes]]), $maxLiteralBytes);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function pair(?ResponseLine $line): array
    {
        self::assertNotNull($line);

        return [$line->tag, $line->text];
    }
}
