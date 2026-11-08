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
use UtafitiLabs\ImapBundle\Client\ImapClient;
use UtafitiLabs\ImapBundle\Exception\ImapCommandFailedException;
use UtafitiLabs\ImapBundle\Exception\ImapConnectionException;
use UtafitiLabs\ImapBundle\Model\MailboxDsn;
use UtafitiLabs\ImapBundle\Tests\Double\ScriptedTransport;

final class ImapClientTest extends TestCase
{
    private const string GREETING = '* OK [CAPABILITY IMAP4rev1 LOGIN-REFERRALS ID ENABLE IDLE SASL-IR LITERAL+ AUTH=PLAIN] IMAP server ready.';
    private const string HEADER_ITEMS = '(UID RFC822.SIZE BODY.PEEK[HEADER.FIELDS (MESSAGE-ID FROM TO CC SUBJECT DATE IN-REPLY-TO REFERENCES AUTHENTICATION-RESULTS)])';

    private MockClock $clock;

    /** @var list<ScriptedTransport> */
    private array $transports = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-08 13:00:00 UTC');
    }

    protected function assertPostConditions(): void
    {
        foreach ($this->transports as $transport) {
            foreach ($transport->commandLines() as $line) {
                self::assertDoesNotMatchRegularExpression(
                    '/^\S+ (UID )?(STORE|COPY|MOVE|EXPUNGE|APPEND|DELETE|SELECT|CREATE|RENAME|SUBSCRIBE|UNSUBSCRIBE)\b/i',
                    $line,
                    'The client only ever reads.',
                );
                self::assertDoesNotMatchRegularExpression('/BODY\[/', $line, 'Every fetch is a PEEK, so \Seen is never set.');
            }
        }
    }

    public function testItReplaysARecordedDovecotSession(): void
    {
        $transport = $this->track(ScriptedTransport::fromTranscript($this->clock, __DIR__.'/../../Fixtures/conversations/dovecot-session.txt'));
        $client = $this->client($transport, new MailboxDsn('imap.example.com', 993, true, 'answers@example.org', 's3cret pass'));

        $client->open();
        self::assertSame(['imap.example.com', 993, true, 30.0], $transport->connectedTo);

        $status = $client->examine('INBOX');
        self::assertSame(1730000000, $status->uidValidity);
        self::assertSame(2, $status->exists);
        self::assertSame(4103, $status->uidNext);

        self::assertSame([4101, 4102], $client->searchSince(new \DateTimeImmutable('2026-11-05 13:00:00 UTC')));

        $headers = $client->fetchHeaders([4101, 4102]);
        self::assertCount(2, $headers);
        self::assertSame([4101, 2876], [$headers[0]->uid, $headers[0]->size]);
        self::assertStringStartsWith('Message-ID: <reply-1@mail.example.com>', $headers[0]->bytes);
        self::assertStringContainsString("dmarc=pass header.from=example.com\r\n\r\n", $headers[0]->bytes);
        self::assertSame([4102, 48213], [$headers[1]->uid, $headers[1]->size]);

        $message = $client->fetch(4101);
        self::assertNotNull($message);
        self::assertSame(4101, $message->uid);
        self::assertSame('2026-11-08T09:58:03+00:00', $message->internalDate?->format(\DATE_ATOM));
        self::assertStringEndsWith("\r\n\r\nYes, the 14th works for us.\r\n", $message->bytes);

        self::assertTrue($client->supportsIdle());
        self::assertTrue($client->idle(1740, static fn (): bool => false));
        self::assertSame([4103], $client->searchUidsFrom(4103));
        self::assertSame(4103, $client->fetchHeaders([4103])[0]->uid);

        $client->logout();
        self::assertTrue($transport->closed);
        self::assertSame([], $transport->remainingSteps());
    }

    public function testItLogsInWithQuotedStringsWhenTheServerOffersNoSaslPlain(): void
    {
        $transport = $this->track(new ScriptedTransport($this->clock, [
            ['S', "* OK [CAPABILITY IMAP4rev1 IDLE] ready\r\n"],
            ['C', "A0001 LOGIN \"user@example.com\" \"p\\\"a\\\\ss\"\r\n"],
            ['S', "A0001 OK Logged in\r\n"],
            ['C', "A0002 CAPABILITY\r\n"],
            ['S', "* CAPABILITY IMAP4rev1 IDLE\r\nA0002 OK done\r\n"],
        ]));

        $this->client($transport, new MailboxDsn('imap.example.com', 993, true, 'user@example.com', 'p"a\\ss'))->open();

        self::assertSame([], $transport->remainingSteps());
    }

    public function testANonAsciiPasswordGoesAsASynchronisingLiteral(): void
    {
        $password = 'pässwörd';
        $transport = $this->track(new ScriptedTransport($this->clock, [
            ['S', "* OK [CAPABILITY IMAP4rev1] ready\r\n"],
            ['C', 'A0001 LOGIN "user" {'.\strlen($password)."}\r\n"],
            ['S', "+ Ready for literal data\r\n"],
            ['C', $password."\r\n"],
            ['S', "A0001 OK [CAPABILITY IMAP4rev1] Logged in\r\n"],
        ]));

        $this->client($transport, new MailboxDsn('imap.example.com', 993, true, 'user', $password))->open();

        self::assertSame([], $transport->remainingSteps());
    }

    public function testItAsksForCapabilitiesWhenTheGreetingCarriesNone(): void
    {
        $transport = $this->track(new ScriptedTransport($this->clock, [
            ['S', "* OK ready\r\n"],
            ['C', "A0001 CAPABILITY\r\n"],
            ['S', "* CAPABILITY IMAP4rev1 AUTH=PLAIN\r\nA0001 OK done\r\n"],
            ['C', "A0002 AUTHENTICATE PLAIN\r\n"],
            ['S', "+ \r\n"],
            ['C', base64_encode("\0user\0secret")."\r\n"],
            ['S', "A0002 OK Logged in\r\n"],
            ['C', "A0003 CAPABILITY\r\n"],
            ['S', "* CAPABILITY IMAP4rev1\r\nA0003 OK done\r\n"],
        ]));

        $client = $this->client($transport, new MailboxDsn('imap.example.com', 993, true, 'user', 'secret'));
        $client->open();

        self::assertFalse($client->supportsIdle());
        self::assertSame([], $transport->remainingSteps());
    }

    public function testAnImapDsnUpgradesWithStarttlsBeforeAnyCredentialIsSent(): void
    {
        $transport = $this->track(new ScriptedTransport($this->clock, [
            ['S', "* OK [CAPABILITY IMAP4rev1 STARTTLS LOGINDISABLED] ready\r\n"],
            ['C', "A0001 STARTTLS\r\n"],
            ['S', "A0001 OK Begin TLS negotiation now.\r\n"],
            ['TLS'],
            ['C', "A0002 CAPABILITY\r\n"],
            ['S', "* CAPABILITY IMAP4rev1 IDLE AUTH=PLAIN\r\nA0002 OK done\r\n"],
            ['C', "A0003 AUTHENTICATE PLAIN\r\n"],
            ['S', "+ \r\n"],
            ['C', base64_encode("\0user\0secret")."\r\n"],
            ['S', "A0003 OK [CAPABILITY IMAP4rev1 IDLE] Logged in\r\n"],
        ]));

        $this->client($transport, new MailboxDsn('imap.example.com', 143, false, 'user', 'secret'))->open();

        self::assertTrue($transport->tlsStarted);
        self::assertSame(['imap.example.com', 143, false, 30.0], $transport->connectedTo);
        self::assertSame([], $transport->remainingSteps());
    }

    public function testItRefusesAServerWithoutStarttlsRatherThanLoggingInInClear(): void
    {
        $transport = $this->track(new ScriptedTransport($this->clock, [
            ['S', "* OK [CAPABILITY IMAP4rev1 AUTH=PLAIN] ready\r\n"],
        ]));

        $this->expectException(ImapConnectionException::class);
        $this->expectExceptionMessage('STARTTLS');

        $this->client($transport, new MailboxDsn('imap.example.com', 143, false, 'user', 'secret'))->open();
    }

    public function testAFailedLoginNamesTheCommandButNeverTheCredentials(): void
    {
        $transport = $this->track(new ScriptedTransport($this->clock, [
            ['S', self::GREETING."\r\n"],
            ['C', 'A0001 AUTHENTICATE PLAIN '.base64_encode("\0user@example.com\0hunter2")."\r\n"],
            ['S', "A0001 NO [AUTHENTICATIONFAILED] Authentication failed.\r\n"],
        ]));

        try {
            $this->client($transport, new MailboxDsn('imap.example.com', 993, true, 'user@example.com', 'hunter2'))->open();
            self::fail('The login must fail.');
        } catch (ImapCommandFailedException $exception) {
            self::assertSame('AUTHENTICATE failed: NO [AUTHENTICATIONFAILED] Authentication failed.', $exception->getMessage());
            self::assertStringNotContainsString('hunter2', $exception->getMessage());
            self::assertStringNotContainsString(base64_encode("\0user@example.com\0hunter2"), $exception->getMessage());
        }
    }

    public function testItRefusesToLogInWhenTheServerDisablesLoginAndOffersNoPlain(): void
    {
        $transport = $this->track(new ScriptedTransport($this->clock, [
            ['S', "* OK [CAPABILITY IMAP4rev1 LOGINDISABLED AUTH=XOAUTH2] ready\r\n"],
        ]));

        $this->expectException(ImapConnectionException::class);
        $this->expectExceptionMessage('no authentication mechanism');

        $this->client($transport, new MailboxDsn('imap.example.com', 993, true, 'user', 'secret'))->open();
    }

    public function testAGreetingWithByeIsAConnectionError(): void
    {
        $transport = $this->track(new ScriptedTransport($this->clock, [['S', "* BYE Too many connections\r\n"]]));

        $this->expectException(ImapConnectionException::class);
        $this->expectExceptionMessage('Too many connections');

        $this->client($transport, $this->dsn())->open();
    }

    public function testIdleEndsWithDoneOnceItsTimeIsUp(): void
    {
        $transport = $this->track($this->loggedIn([
            ['C', "A0002 IDLE\r\n"],
            ['S', "+ idling\r\n"],
            ['C', "DONE\r\n"],
            ['S', "A0002 OK Idle completed.\r\n"],
        ]));
        $client = $this->openClient($transport);

        self::assertFalse($client->idle(1740, static fn (): bool => false));
        self::assertSame('2026-11-08 13:29:00', $this->clock->now()->format('Y-m-d H:i:s'));
        self::assertSame([], $transport->remainingSteps());
    }

    public function testIdleEndsWithDoneAsSoonAsAStopIsAsked(): void
    {
        $transport = $this->track($this->loggedIn([
            ['C', "A0002 IDLE\r\n"],
            ['S', "+ idling\r\n"],
            ['C', "DONE\r\n"],
            ['S', "A0002 OK Idle completed.\r\n"],
        ]));
        $client = $this->openClient($transport);
        $polls = 0;

        self::assertFalse($client->idle(1740, static function () use (&$polls): bool {
            return ++$polls > 3;
        }));
        self::assertSame('2026-11-08 13:00:03', $this->clock->now()->format('Y-m-d H:i:s'));
    }

    public function testMailAnnouncedDuringAnEarlierCommandSkipsTheNextIdle(): void
    {
        $transport = $this->track($this->loggedIn([
            ['C', "A0002 NOOP\r\n"],
            ['S', "* 5 EXISTS\r\nA0002 OK NOOP completed.\r\n"],
        ]));
        $client = $this->openClient($transport);
        $client->noop();

        self::assertTrue($client->idle(1740, static fn (): bool => false));
        self::assertSame([], $transport->remainingSteps());
    }

    public function testAByeDuringIdleIsAConnectionError(): void
    {
        $transport = $this->track($this->loggedIn([
            ['C', "A0002 IDLE\r\n"],
            ['S', "+ idling\r\n* BYE Server shutting down\r\n"],
        ]));
        $client = $this->openClient($transport);

        $this->expectException(ImapConnectionException::class);
        $client->idle(1740, static fn (): bool => false);
    }

    public function testASearchFromAUidLeavesOutTheLastMessageAServerReturnsForAnEmptyRange(): void
    {
        $transport = $this->track($this->loggedIn([
            ['C', "A0002 UID SEARCH UID 4104:*\r\n"],
            ['S', "* SEARCH 4103\r\nA0002 OK Search completed.\r\n"],
        ]));

        self::assertSame([], $this->openClient($transport)->searchUidsFrom(4104));
    }

    public function testAMessageExpungedBeforeItsFetchIsNull(): void
    {
        $transport = $this->track($this->loggedIn([
            ['C', "A0002 UID FETCH 77 (UID RFC822.SIZE INTERNALDATE BODY.PEEK[])\r\n"],
            ['S', "A0002 OK Fetch completed.\r\n"],
        ]));

        self::assertNull($this->openClient($transport)->fetch(77));
    }

    public function testFetchesIgnoreUnsolicitedFlagUpdatesAndReadQuotedBodies(): void
    {
        $transport = $this->track($this->loggedIn([
            ['C', 'A0002 UID FETCH 8,9 '.self::HEADER_ITEMS."\r\n"],
            ['S', "* 3 FETCH (FLAGS (\\Seen) UID 2)\r\n"],
            ['S', "* 4 FETCH (UID 8 RFC822.SIZE 120 BODY[HEADER.FIELDS (MESSAGE-ID)] \"Message-ID: <a@example.com>\")\r\n"],
            ['S', "* 5 FETCH (UID 9 RFC822.SIZE 99 BODY[HEADER.FIELDS (MESSAGE-ID)] NIL)\r\n"],
            ['S', "A0002 OK Fetch completed.\r\n"],
        ]));

        $headers = $this->openClient($transport)->fetchHeaders([8, 9]);

        self::assertCount(2, $headers);
        self::assertSame('Message-ID: <a@example.com>', $headers[0]->bytes);
        self::assertSame('', $headers[1]->bytes);
    }

    public function testAFolderNameIsQuotedInModifiedUtf7(): void
    {
        $transport = $this->track($this->loggedIn([
            ['C', "A0002 EXAMINE \"Antworten/&AOQ-rger\"\r\n"],
            ['S', "* 0 EXISTS\r\n* OK [UIDVALIDITY 7] ok\r\nA0002 OK [READ-ONLY] done\r\n"],
        ]));

        $status = $this->openClient($transport)->examine('Antworten/ärger');

        self::assertSame([7, 0, null], [$status->uidValidity, $status->exists, $status->uidNext]);
    }

    public function testASilentServerTimesACommandOut(): void
    {
        $transport = $this->track($this->loggedIn([
            ['C', "A0002 NOOP\r\n"],
            ['C', 'never'],
        ]));
        $client = $this->openClient($transport);

        $this->expectException(ImapConnectionException::class);
        $this->expectExceptionMessage('NOOP timed out');
        $client->noop();
    }

    public function testTheClientOffersNoMethodThatWritesToTheMailbox(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => strtolower($method->getName()),
            (new \ReflectionClass(ImapClient::class))->getMethods(),
        );

        foreach (['store', 'copy', 'move', 'expunge', 'append', 'delete', 'select', 'create', 'rename'] as $verb) {
            self::assertNotContains($verb, $methods);
        }
    }

    /**
     * @param list<array{0: 'C'|'S'|'EOF'|'TLS', 1?: string}> $steps
     */
    private function loggedIn(array $steps): ScriptedTransport
    {
        return new ScriptedTransport($this->clock, [
            ['S', self::GREETING."\r\n"],
            ['C', 'A0001 AUTHENTICATE PLAIN '.base64_encode("\0user\0secret")."\r\n"],
            ['S', "A0001 OK [CAPABILITY IMAP4rev1 IDLE] Logged in\r\n"],
            ...$steps,
        ]);
    }

    private function openClient(ScriptedTransport $transport): ImapClient
    {
        $client = $this->client($transport, $this->dsn());
        $client->open();

        return $client;
    }

    private function client(ScriptedTransport $transport, MailboxDsn $dsn): ImapClient
    {
        return new ImapClient($transport, $dsn, $this->clock, 1048576);
    }

    private function dsn(): MailboxDsn
    {
        return new MailboxDsn('imap.example.com', 993, true, 'user', 'secret');
    }

    private function track(ScriptedTransport $transport): ScriptedTransport
    {
        $this->transports[] = $transport;

        return $transport;
    }
}
