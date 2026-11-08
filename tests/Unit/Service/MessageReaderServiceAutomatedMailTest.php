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
use UtafitiLabs\ImapBundle\Client\ImapClient;
use UtafitiLabs\ImapBundle\Model\DeliveryFailure;
use UtafitiLabs\ImapBundle\Model\FailedRecipient;
use UtafitiLabs\ImapBundle\Model\Message;
use UtafitiLabs\ImapBundle\Service\MessageReaderService;

final class MessageReaderServiceAutomatedMailTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function fixtures(): iterable
    {
        yield 'a person\'s reply' => ['human-reply', false, false];
        yield 'a Gmail reply' => ['gmail-reply', false, false];
        yield 'an Outlook reply' => ['outlook-reply', false, false];
        yield 'an Apple Mail reply' => ['apple-mail-reply', false, false];
        yield 'a Yahoo reply' => ['yahoo-reply', false, false];
        yield 'an Exim bounce' => ['exim-bounce', true, true];
        yield 'a Postfix bounce' => ['postfix-bounce', true, true];
        yield 'a Gmail delivery status notification' => ['gmail-dsn', true, true];
        yield 'an Exchange non-delivery report' => ['exchange-ndr', true, true];
        yield 'an out-of-office reply' => ['out-of-office', true, false];
        yield 'a Microsoft automatic reply' => ['microsoft-auto-reply', true, false];
        yield 'a mailing list message' => ['mailing-list', true, false];
    }

    #[DataProvider('fixtures')]
    public function testTheFetchedHeaderFieldsTellAutomatedMailAndDeliveryReportsApart(string $fixture, bool $automated, bool $deliveryReport): void
    {
        $headers = (new MessageReaderService())->readHeaders($this->fetchedHeaderFields($this->raw($fixture)));

        self::assertSame($automated, $headers->isAutomated());
        self::assertSame($deliveryReport, $headers->isDeliveryReport());
    }

    #[DataProvider('fixtures')]
    public function testOnlyADeliveryReportCarriesADeliveryFailure(string $fixture, bool $automated, bool $deliveryReport): void
    {
        $message = (new MessageReaderService())->read($this->raw($fixture));

        self::assertSame($automated, $message->headers->isAutomated());
        self::assertSame($deliveryReport, $message->headers->isDeliveryReport());
        self::assertSame($deliveryReport, null !== $message->deliveryFailure);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function automatedSignals(): iterable
    {
        yield 'no signal' => ['', false];
        yield 'Auto-Submitted: auto-replied' => ["Auto-Submitted: auto-replied\r\n", true];
        yield 'Auto-Submitted: auto-generated with a comment' => ["Auto-Submitted: auto-generated (scheduler); owner-email=\"a@example.com\"\r\n", true];
        yield 'Auto-Submitted: no' => ["Auto-Submitted: No (written by a person)\r\n", false];
        yield 'a multipart/report' => ["Content-Type: multipart/report; report-type=disposition-notification; boundary=b\r\n", true];
        yield 'an empty Return-Path' => ["Return-Path: < >\r\n", true];
        yield 'a Return-Path with an address' => ["Return-Path: <jane@example.com>\r\n", false];
        yield 'From MAILER-DAEMON' => ["From: MAILER-DAEMON@mail.example.org (Mail Delivery System)\r\n", true];
        yield 'From a postmaster' => ["From: \"Postmaster\" <PostMaster@example.net>\r\n", true];
        yield 'From a bare mailer-daemon' => ["From: <Mailer-Daemon>\r\n", true];
        yield 'a person named Postmaster' => ["From: Postmaster <jane@example.com>\r\n", false];
        yield 'X-Autoreply' => ["X-Autoreply: yes\r\n", true];
        yield 'X-Autorespond' => ["X-Autorespond: Away until Monday\r\n", true];
        yield 'X-Auto-Response-Suppress' => ["X-Auto-Response-Suppress: All\r\n", true];
        yield 'Precedence: bulk' => ["Precedence: bulk\r\n", true];
        yield 'Precedence: junk' => ["Precedence: Junk\r\n", true];
        yield 'Precedence: auto_reply' => ["Precedence: auto_reply\r\n", true];
        yield 'Precedence: list' => ["Precedence: list\r\n", true];
        yield 'Precedence: first-class' => ["Precedence: first-class\r\n", false];
        yield 'List-Id' => ["List-Id: <notes.lists.example.net>\r\n", true];
        yield 'List-Unsubscribe' => ["List-Unsubscribe: <mailto:leave@lists.example.net>\r\n", true];
    }

    #[DataProvider('automatedSignals')]
    public function testEachSignalOfAutomatedMail(string $field, bool $automated): void
    {
        $from = str_starts_with($field, 'From:') ? '' : "From: Jane <jane@example.com>\r\n";
        $headers = (new MessageReaderService())->readHeaders("Message-ID: <a@example.com>\r\n".$from.$field."Subject: Hello\r\n\r\n");

        self::assertSame($automated, $headers->isAutomated());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function deliveryReportSignals(): iterable
    {
        yield 'a delivery-status report' => ["From: a@example.org\r\nContent-Type: multipart/report; report-type=\"Delivery-Status\"; boundary=b\r\n", true];
        yield 'a read receipt' => ["From: a@example.org\r\nContent-Type: multipart/report; report-type=disposition-notification; boundary=b\r\n", false];
        yield 'Mail delivery failed from Mailer-Daemon' => ["From: Mail Delivery System <Mailer-Daemon@mail.example.org>\r\nSubject: Mail delivery failed: returning message to sender\r\n", true];
        yield 'Undelivered Mail from MAILER-DAEMON' => ["From: MAILER-DAEMON@mail.example.org\r\nSubject: Undelivered Mail Returned to Sender\r\n", true];
        yield 'a failure notification from a postmaster' => ["From: postmaster@example.net\r\nSubject: Delivery Status Notification (Failure)\r\n", true];
        yield 'Undeliverable from a postmaster' => ["From: postmaster@example.net\r\nSubject: Undeliverable: Re: Your question\r\n", true];
        yield 'a delay warning from Mailer-Daemon' => ["From: Mailer-Daemon@mail.example.org\r\nSubject: Warning: message 1vHq3t-0004Zk-1a delayed 24 hours\r\n", false];
        yield 'a person writing about a failure' => ["From: jane@example.com\r\nSubject: Mail delivery failed again?\r\n", false];
    }

    #[DataProvider('deliveryReportSignals')]
    public function testWhatMakesADeliveryReport(string $fields, bool $deliveryReport): void
    {
        $headers = (new MessageReaderService())->readHeaders($fields."\r\n");

        self::assertSame($deliveryReport, $headers->isDeliveryReport());
        self::assertTrue(!$deliveryReport || $headers->isAutomated());
    }

    public function testAnEximBounceWithoutADeliveryStatusPart(): void
    {
        self::assertEquals(new DeliveryFailure(
            [
                new FailedRecipient('nobody@example.net', 'Unrouteable address', null, 'Unrouteable address'),
                new FailedRecipient(
                    'gone@example.com',
                    'Recipient address rejected: User unknown in local recipient table',
                    '5.1.1',
                    'host mx.example.com [192.0.2.25] SMTP error from remote mail server after RCPT TO:<gone@example.com>: 550 5.1.1 <gone@example.com>: Recipient address rejected: User unknown in local recipient table',
                ),
            ],
            '<confirmation-41.7f3a9c@example.org>',
            'Unrouteable address',
        ), $this->read('exim-bounce')->deliveryFailure);
    }

    public function testAPostfixBounce(): void
    {
        self::assertEquals(new DeliveryFailure(
            [new FailedRecipient(
                'nobody@example.net',
                'Recipient address rejected: User unknown in virtual mailbox table',
                '5.1.1',
                '550 5.1.1 <nobody@example.net>: Recipient address rejected: User unknown in virtual mailbox table',
            )],
            '<reply-7.a1b2c3@example.org>',
            'Recipient address rejected: User unknown in virtual mailbox table',
        ), $this->read('postfix-bounce')->deliveryFailure);
    }

    public function testAGmailDeliveryStatusNotification(): void
    {
        $failure = $this->read('gmail-dsn')->deliveryFailure;
        $reason = 'The email account that you tried to reach does not exist. Please try double-checking the recipient\'s email address for typos or unnecessary spaces. For more information, go to https://support.example.com/mail/?p=NoSuchUser a1b2c3d4e5.25 - gsmtp';

        self::assertNotNull($failure);
        self::assertSame('<offer-3.9d8e7f@example.org>', $failure->originalMessageId);
        self::assertSame($reason, $failure->reason);
        self::assertCount(1, $failure->recipients);
        self::assertSame(['no.such.person@example.com', '5.1.1', $reason], [$failure->recipients[0]->email, $failure->recipients[0]->status, $failure->recipients[0]->reason]);
    }

    public function testAnExchangeNonDeliveryReport(): void
    {
        $message = $this->read('exchange-ndr');

        self::assertSame('<reply-9.ffee01@example.org>', $message->headers->inReplyTo);
        self::assertEquals(new DeliveryFailure(
            [new FailedRecipient(
                'Former.Colleague@example.net',
                'RESOLVER.ADR.RecipientNotFound; Recipient not found by SMTP address lookup',
                '5.1.10',
                '550 5.1.10 RESOLVER.ADR.RecipientNotFound; Recipient not found by SMTP address lookup',
            )],
            '<reply-9.ffee01@example.org>',
            'RESOLVER.ADR.RecipientNotFound; Recipient not found by SMTP address lookup',
        ), $message->deliveryFailure);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function statusMeanings(): iterable
    {
        yield '5.1.1' => ['5.1.1', 'The mailbox does not exist'];
        yield '5.1.2' => ['5.1.2', 'The recipient\'s domain does not exist'];
        yield '5.2.1' => ['5.2.1', 'The mailbox is disabled'];
        yield '5.2.2' => ['5.2.2', 'The mailbox is full'];
        yield '5.2.3' => ['5.2.3', 'The message is too large for the mailbox'];
        yield '5.3.4' => ['5.3.4', 'The message is too large'];
        yield '5.4.4' => ['5.4.4', 'The recipient\'s server could not be reached'];
        yield '4.4.7' => ['4.4.7', 'The message expired before it could be delivered'];
        yield '5.7.1' => ['5.7.1', 'The recipient\'s server refused the message'];
        yield 'an unknown status' => ['5.9.9', 'The message could not be delivered'];
        yield 'no status' => ['', 'The message could not be delivered'];
    }

    #[DataProvider('statusMeanings')]
    public function testWithoutADiagnosticTheStatusGivesTheReason(string $status, string $reason): void
    {
        $failure = (new MessageReaderService())->read($this->report("Final-Recipient: rfc822; full@example.net\r\nAction: failed\r\nStatus: ".$status."\r\nDiagnostic-Code: smtp; 552 ".$status."\r\n"))->deliveryFailure;

        self::assertNotNull($failure);
        self::assertSame($reason, $failure->reason);
        self::assertSame('' === $status ? null : $status, $failure->recipients[0]->status);
    }

    public function testOnlyFailedRecipientsAreListedAndTheOriginalRecipientIsPreferred(): void
    {
        $failure = (new MessageReaderService())->read($this->report(
            "Final-Recipient: rfc822; ok@example.net\r\nAction: delivered\r\nStatus: 2.0.0\r\n\r\n"
            ."Original-Recipient: rfc822;<Alias@example.org>\r\nFinal-Recipient: rfc822; mailbox@example.net\r\nAction: Failed\r\nStatus: 5.2.2\r\n\r\n"
            ."Final-Recipient: rfc822; later@example.net\r\nAction: delayed\r\nStatus: 4.4.1\r\n",
        ))->deliveryFailure;

        self::assertEquals(new DeliveryFailure([new FailedRecipient('Alias@example.org', 'The mailbox is full', '5.2.2', null)], null, 'The mailbox is full'), $failure);
    }

    public function testADelayReportIsADeliveryReportWithoutAFailure(): void
    {
        $message = (new MessageReaderService())->read($this->report("Final-Recipient: rfc822; later@example.net\r\nAction: delayed\r\nStatus: 4.4.1\r\n"));

        self::assertTrue($message->headers->isDeliveryReport());
        self::assertNull($message->deliveryFailure);
    }

    public function testTheOriginalMessageIdFallsBackToTheReportingServersNote(): void
    {
        $failure = (new MessageReaderService())->read($this->report("X-Original-Message-ID: <sent-1@example.org>\r\n\r\nFinal-Recipient: rfc822; gone@example.net\r\nAction: failed\r\nStatus: 5.1.1\r\n"))->deliveryFailure;

        self::assertSame('<sent-1@example.org>', $failure?->originalMessageId);
    }

    public function testABounceWhoseOriginalIsAttachedAndWhoseAddressesCannotBeRead(): void
    {
        $failure = (new MessageReaderService())->read(
            "From: MAILER-DAEMON@mail.example.org\r\nSubject: failure notice\r\nContent-Type: multipart/mixed; boundary=b\r\n\r\n"
            ."--b\r\nContent-Type: text/plain\r\n\r\nSorry, we were unable to deliver your message.\r\n"
            ."--b\r\nContent-Type: message/rfc822\r\n\r\nMessage-ID: <sent-2@example.org>\r\nSubject: Hello\r\n\r\nHello.\r\n--b--\r\n",
        )->deliveryFailure;

        self::assertEquals(new DeliveryFailure([], '<sent-2@example.org>', 'The message could not be delivered'), $failure);
    }

    public function testAQuotedOriginalWithoutAMarkerStillGivesItsMessageId(): void
    {
        $failure = (new MessageReaderService())->read(
            "From: Mailer-Daemon@mail.example.org\r\nSubject: Mail delivery failed\r\n\r\n"
            ."The following address(es) failed:\r\n\r\n  <gone@example.net>:\r\n\r\nOriginal headers:\r\nMessage-ID:\r\n <sent-3@example.org>\r\n",
        )->deliveryFailure;

        self::assertEquals(new DeliveryFailure([new FailedRecipient('gone@example.net', 'The message could not be delivered', null, null)], '<sent-3@example.org>', 'The message could not be delivered'), $failure);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenReports(): iterable
    {
        yield 'a report without a boundary' => ["Content-Type: multipart/report; report-type=delivery-status\r\n\r\nbody"];
        yield 'an empty delivery status' => ["Content-Type: multipart/report; report-type=delivery-status; boundary=b\r\n\r\n--b\r\nContent-Type: message/delivery-status\r\n\r\n\r\n--b--"];
        yield 'a recipient without an address' => ["Content-Type: multipart/report; report-type=delivery-status; boundary=b\r\n\r\n--b\r\nContent-Type: message/delivery-status\r\n\r\nFinal-Recipient: rfc822;\r\nAction: failed\r\n--b--"];
        yield 'a bounce subject and nothing else' => ["From: postmaster@example.net\r\nSubject: Undeliverable"];
        yield 'deep nesting' => ['Content-Type: multipart/report; report-type=delivery-status; boundary=b'."\r\n\r\n".str_repeat("--b\r\nContent-Type: multipart/mixed; boundary=b\r\n\r\n", 60)];
    }

    #[DataProvider('brokenReports')]
    public function testABrokenReportNeverThrows(string $raw): void
    {
        $message = (new MessageReaderService())->read($raw);

        self::assertTrue($message->headers->isDeliveryReport());
        self::assertSame([], $message->deliveryFailure->recipients ?? []);
    }

    private function report(string $deliveryStatus): string
    {
        return "From: MAILER-DAEMON@mail.example.org\r\nSubject: Delivery Status Notification\r\nContent-Type: multipart/report; report-type=delivery-status; boundary=b\r\n\r\n"
            ."--b\r\nContent-Type: text/plain\r\n\r\nA report.\r\n"
            ."--b\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; mail.example.org\r\n\r\n".$deliveryStatus
            ."--b--\r\n";
    }

    private function read(string $fixture): Message
    {
        return (new MessageReaderService())->read($this->raw($fixture));
    }

    private function raw(string $fixture): string
    {
        return str_replace("\n", "\r\n", (string) file_get_contents(__DIR__.'/../../Fixtures/eml/'.$fixture.'.eml'));
    }

    /**
     * The header block the watcher fetches: only the fields of
     * ImapClient::HEADER_FIELDS, as a server returns them.
     */
    private function fetchedHeaderFields(string $raw): string
    {
        $wanted = array_map(strtolower(...), explode(' ', ImapClient::HEADER_FIELDS));
        [$headerBlock] = explode("\r\n\r\n", $raw, 2);
        $kept = '';
        foreach (preg_split('/\r\n(?![ \t])/', $headerBlock) ?: [] as $field) {
            if (\in_array(strtolower(trim(strstr($field, ':', true) ?: '')), $wanted, true)) {
                $kept .= $field."\r\n";
            }
        }

        return $kept."\r\n";
    }
}
