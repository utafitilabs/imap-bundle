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
use UtafitiLabs\ImapBundle\Service\MessageReaderService;
use UtafitiLabs\ImapBundle\Service\QuotedReplyStripperService;

final class QuotedReplyStripperServiceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function replies(): iterable
    {
        yield 'Gmail' => ['gmail-reply', "Yes, the 14th works for us — two people.\n\nThanks,\nJane"];
        yield 'Outlook' => ['outlook-reply', "Hello,\n\nThe price of €450 per person is fine. We’ll pay the deposit on Monday.\n\nKind regards\nJohn"];
        yield 'Apple Mail' => ['apple-mail-reply', "Sounds wonderful, we’ll take the larger room.\n\nKim"];
        yield 'Yahoo' => ['yahoo-reply', " Please book the March dates.\nSam"];
    }

    #[DataProvider('replies')]
    public function testItKeepsOnlyTheNewTextOfAReply(string $fixture, string $expected): void
    {
        $text = (new MessageReaderService())->read((string) file_get_contents(__DIR__.'/../../Fixtures/eml/'.$fixture.'.eml'))->textBody;

        self::assertSame($expected, (new QuotedReplyStripperService())->strip($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function quotes(): iterable
    {
        yield 'attribution wrapped over two lines' => [
            "Fine.\n\nOn Sat, Nov 8, 2026 at 10:00 AM Jane Doe <\njane@example.com> wrote:\n> old",
            'Fine.',
        ];
        yield 'Apple Mail attribution' => ["Yes\n\nOn 8 Nov 2026, at 10:00, Jane Doe <jane@example.com> wrote:\n\nold", 'Yes'];
        yield 'Thunderbird attribution' => ["Yes\n\nOn 08/11/2026 10:00, Jane Doe wrote:\n> old", 'Yes'];
        yield 'German attribution' => ["Ja\n\nAm 08.11.2026 um 10:00 schrieb Jane Doe <jane@example.com>:\n> alt", 'Ja'];
        yield 'French attribution' => ["Oui\n\nLe sam. 8 nov. 2026 à 10:00, Jane Doe <jane@example.com> a écrit :\n> ancien", 'Oui'];
        yield 'Spanish attribution' => ["Sí\n\nEl sáb, 8 nov 2026 a las 10:00, Jane Doe (<jane@example.com>) escribió:\n> viejo", 'Sí'];
        yield 'Original Message separator' => ["Agreed.\n\n-----Original Message-----\nFrom: x", 'Agreed.'];
        yield 'spaced Original Message separator' => ["Agreed.\n----- Original Message -----\nFrom: x", 'Agreed.'];
        yield 'Outlook rule then From' => ["Agreed.\n\n________________________________\nFrom: Answers <answers@example.org>\nSent: Friday", 'Agreed.'];
        yield 'bold Outlook header block' => ["Agreed.\n\n*From:* Answers <answers@example.org>\n*Sent:* Friday\n*To:* John", 'Agreed.'];
        yield 'From line without a Sent line stays' => ["From: the airport we take a taxi.\nSee you.", "From: the airport we take a taxi.\nSee you."];
        yield 'signature delimiter' => ["See you.\n-- \nJane Doe\nExample Ltd", 'See you.'];
        yield 'interleaved quotes are dropped, answers kept' => ["> Which date?\nThe 14th.\n> How many?\nTwo.", "The 14th.\nTwo."];
        yield 'mobile signature at the end' => ["See you.\n\nSent from my iPhone", 'See you.'];
        yield 'CRLF text' => ["Fine.\r\n\r\nOn Fri, Nov 7, 2026 at 4:12 PM Answers <answers@example.org> wrote:\r\n> old", 'Fine.'];
        yield 'nothing quoted' => ["Just this.\n", 'Just this.'];
    }

    #[DataProvider('quotes')]
    public function testItStripsTheQuotedPart(string $text, string $expected): void
    {
        self::assertSame($expected, (new QuotedReplyStripperService())->strip($text));
    }
}
