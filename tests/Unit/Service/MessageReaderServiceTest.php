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
use UtafitiLabs\ImapBundle\Model\Address;
use UtafitiLabs\ImapBundle\Model\Message;
use UtafitiLabs\ImapBundle\Service\MessageReaderService;

final class MessageReaderServiceTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
    }

    #[DataProvider('lineEndings')]
    public function testAGmailReply(string $eol): void
    {
        $message = $this->fixture('gmail-reply', $eol);
        $headers = $message->headers;

        self::assertSame('<CAF3x9q=reply1@mail.example.com>', $headers->messageId);
        self::assertEquals(new Address('jane@example.com', 'Jane Doe'), $headers->from);
        self::assertEquals([new Address('answers@example.org', 'Answers')], $headers->to);
        self::assertSame('Re: Your enquiry about the northern circuit', $headers->subject);
        self::assertSame('2026-11-08T12:58:00+03:00', $headers->date?->format(\DATE_ATOM));
        self::assertSame('<enquiry-12@example.org>', $headers->inReplyTo);
        self::assertSame(['<enquiry-12@example.org>', '<enquiry-12-ack@example.org>'], $headers->references);
        self::assertStringStartsWith("Yes, the 14th works for us — two adults.\n\nThanks,\nJane\n\nOn Fri, Nov 7, 2026 at 4:12\u{202F}PM Answers", $message->textBody);
        self::assertStringContainsString('<div class="gmail_quote">', (string) $message->htmlBody);
        self::assertSame([], $message->attachments);
    }

    #[DataProvider('lineEndings')]
    public function testAnOutlookReplyInWindows1252(string $eol): void
    {
        $message = $this->fixture('outlook-reply', $eol);
        $headers = $message->headers;

        self::assertSame('<DB9PR01MB1234ABCD@DB9PR01MB1234.eurprd01.prod.example.net>', $headers->messageId);
        self::assertEquals(new Address('john@example.net', 'Doe, John'), $headers->from);
        self::assertEquals([new Address('answers@example.org', 'answers@example.org'), new Address('mary@example.net', 'Mary Roe')], $headers->to);
        self::assertEquals([new Address('renee@example.net', 'Renée Roe')], $headers->cc);
        self::assertSame('RE: Your enquiry', $headers->subject);
        self::assertStringContainsString('The price of €450 per person is fine. We’ll pay the deposit on Monday.', $message->textBody);
        self::assertStringContainsString("\nFrom: Answers <answers@example.org>\nSent: 07 November 2026 16:12", $message->textBody);
    }

    #[DataProvider('lineEndings')]
    public function testAnAppleMailReply(string $eol): void
    {
        $message = $this->fixture('apple-mail-reply', $eol);

        self::assertSame('<5F1E2D3C-4B5A-6978-8796-A5B4C3D2E1F0@example.com>', $message->headers->messageId);
        self::assertEquals([new Address('pat@example.com', 'Pat Lee')], $message->headers->cc);
        self::assertEquals([new Address('answers@example.org', null)], $message->headers->to);
        self::assertStringStartsWith("Sounds wonderful, we’ll take the lodge.\n\nKim\n\n> On 7 Nov 2026, at 17:20,", $message->textBody);
        self::assertNull($message->htmlBody);
    }

    #[DataProvider('lineEndings')]
    public function testAYahooReply(string $eol): void
    {
        $message = $this->fixture('yahoo-reply', $eol);

        self::assertSame('2026-11-08T09:15:44+00:00', $message->headers->date?->format(\DATE_ATOM));
        self::assertEquals(new Address('sam_poe@example.com', 'Sam Poe'), $message->headers->from);
        self::assertStringStartsWith(" Please book the March dates.\nSam\n    On Friday, November 7, 2026", $message->textBody);
    }

    #[DataProvider('lineEndings')]
    public function testQuotedPrintableLatin1(string $eol): void
    {
        $message = $this->fixture('quoted-printable-latin1', $eol);

        self::assertEquals(new Address('lea@example.fr', 'Léa Martin'), $message->headers->from);
        self::assertSame('Réponse à votre demande', $message->headers->subject);
        self::assertSame("Bonjour, le café crème à l'hôtel était délicieux. Une très longue ligne coupée.", $message->textBody);
    }

    #[DataProvider('lineEndings')]
    public function testBase64Utf8(string $eol): void
    {
        self::assertSame("Habari! Karibu sana — 東京 ✓\nAsante.", $this->fixture('base64-utf8', $eol)->textBody);
    }

    #[DataProvider('lineEndings')]
    public function testEncodedWordsSplittingACharacterAndAnUnknownCharset(string $eol): void
    {
        $message = $this->fixture('encoded-word-subject', $eol);

        self::assertSame('Grüße aus Zürich ✓', $message->headers->subject);
        self::assertEquals(new Address('juergen@example.de', 'Jürgen Müller'), $message->headers->from);
        self::assertSame('Viele Grüße', $message->textBody);
    }

    #[DataProvider('lineEndings')]
    public function testNestedMultipartWithAttachments(string $eol): void
    {
        $message = $this->fixture('nested-multipart-attachment', $eol);

        self::assertSame('Passports attached.', $message->textBody);
        self::assertSame('<p>Passports attached.<img src="cid:logo@example.no"></p>', $message->htmlBody);
        self::assertCount(3, $message->attachments);
        self::assertSame(['logo.png', 'image/png', 68], [$message->attachments[0]->filename, $message->attachments[0]->contentType, $message->attachments[0]->size]);
        self::assertSame(['résumé.pdf', 'application/pdf', 656], [$message->attachments[1]->filename, $message->attachments[1]->contentType, $message->attachments[1]->size]);
        self::assertSame(['earlier.eml', 'message/rfc822'], [$message->attachments[2]->filename, $message->attachments[2]->contentType]);
    }

    #[DataProvider('lineEndings')]
    public function testHtmlOnlyFallsBackToStrippedText(string $eol): void
    {
        $message = $this->fixture('html-only', $eol);

        self::assertSame("Booking confirmed\n\nDear Jane,\nyour room is reserved & paid.\n\n- Arrival: 14 Nov\n- Nights: 3", $message->textBody);
        self::assertStringContainsString('<h1>Booking   confirmed</h1>', (string) $message->htmlBody);
    }

    public function testItReadsTheFetchedHeaderFieldsWithTheAuthenticationVerdicts(): void
    {
        $headers = (new MessageReaderService())->readHeaders(
            "Message-ID: <a@example.com>\r\nFrom: a@example.com\r\nAuthentication-Results: mx.example.org;\r\n dkim=pass header.d=example.com;\r\n spf=pass smtp.mailfrom=example.com\r\nAuthentication-Results: other.example.org; dmarc=fail\r\n\r\n",
        );

        self::assertSame('<a@example.com>', $headers->messageId);
        self::assertSame([
            'mx.example.org; dkim=pass header.d=example.com; spf=pass smtp.mailfrom=example.com',
            'other.example.org; dmarc=fail',
        ], $headers->authenticationResults);
    }

    public function testAMessageWithoutMessageIdTakesTheFallbackOrAStableHash(): void
    {
        $reader = new MessageReaderService();
        $raw = "From: a@example.com\r\nSubject: x\r\n\r\nbody";

        self::assertSame('<7.42@imap.invalid>', $reader->readHeaders($raw, '<7.42@imap.invalid>')->messageId);
        self::assertMatchesRegularExpression('/^<[0-9a-f]{64}@imap\.invalid>$/', $reader->readHeaders($raw)->messageId);
        self::assertSame($reader->readHeaders($raw)->messageId, $reader->readHeaders($raw)->messageId);
    }

    public function testAnUnreadableDateTakesTheFallback(): void
    {
        $fallback = new \DateTimeImmutable('2026-11-08 10:00:00 UTC');

        self::assertSame($fallback, (new MessageReaderService())->read("Date: someday\r\n\r\nx", null, $fallback)->headers->date);
    }

    public function testFlowedTextIsJoinedAgain(): void
    {
        $message = (new MessageReaderService())->read("Content-Type: text/plain; charset=utf-8; format=flowed; delsp=yes\r\n\r\nA line that was  \r\nwrapped.\r\n Stuffed line.\r\n-- \r\nSig\r\n");

        self::assertSame("A line that was wrapped.\nStuffed line.\n-- \nSig", $message->textBody);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenMessages(): iterable
    {
        yield 'empty' => [''];
        yield 'headers only' => ['Subject: hi'];
        yield 'multipart without boundary' => ["Content-Type: multipart/mixed\r\n\r\nbody"];
        yield 'unterminated multipart' => ["Content-Type: multipart/mixed; boundary=b\r\n\r\n--b\r\nContent-Type: text/plain\r\n\r\ntext"];
        yield 'bad base64' => ["Content-Transfer-Encoding: base64\r\n\r\n!!!***"];
        yield 'invalid utf-8' => ["Content-Type: text/plain; charset=utf-8\r\nSubject: =?utf-8?B?/w==?=\r\n\r\n\xff\xfe ok"];
        yield 'bogus encoded word' => ["Subject: =?nope?Q?x?= =?utf-8?X?y?=\r\n\r\n"];
        yield 'deep nesting' => [str_repeat("Content-Type: multipart/mixed; boundary=b\r\n\r\n--b\r\n", 60)];
    }

    #[DataProvider('brokenMessages')]
    public function testBrokenContentNeverThrows(string $raw): void
    {
        $message = (new MessageReaderService())->read($raw);

        self::assertTrue(mb_check_encoding($message->textBody, 'UTF-8'));
        self::assertTrue(mb_check_encoding($message->headers->subject, 'UTF-8'));
    }

    private function fixture(string $name, string $eol): Message
    {
        $raw = (string) file_get_contents(__DIR__.'/../../Fixtures/eml/'.$name.'.eml');

        return (new MessageReaderService())->read("\r\n" === $eol ? str_replace("\n", "\r\n", $raw) : $raw);
    }
}
