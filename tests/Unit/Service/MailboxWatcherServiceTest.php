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

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use UtafitiLabs\ImapBundle\Exception\ImapCommandFailedException;
use UtafitiLabs\ImapBundle\Exception\ImapConnectionException;
use UtafitiLabs\ImapBundle\Model\Mailbox;
use UtafitiLabs\ImapBundle\Model\Message;
use UtafitiLabs\ImapBundle\Model\MessageHeaders;
use UtafitiLabs\ImapBundle\Service\IncomingMailHandlerInterface;
use UtafitiLabs\ImapBundle\Service\MailboxWatcherService;
use UtafitiLabs\ImapBundle\Service\MessageReaderService;
use UtafitiLabs\ImapBundle\Tests\Double\FakeImapClient;
use UtafitiLabs\ImapBundle\Tests\Double\FakeImapClientFactory;
use UtafitiLabs\ImapBundle\Tests\Double\RecordingHandler;
use UtafitiLabs\ImapBundle\Tests\Double\RecordingLogger;

final class MailboxWatcherServiceTest extends TestCase
{
    private const string DSN = 'imaps://answers%40example.org:s3cret@imap.example.com';

    private MockClock $clock;
    private RecordingLogger $logger;
    private bool $stop = false;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-08 13:00:00 UTC');
        $this->logger = new RecordingLogger();
    }

    public function testItCatchesUpOnTheLastDaysThenIdles(): void
    {
        $client = new FakeImapClient([11 => $this->mail(1), 12 => $this->mail(2)]);
        $client->idleSteps = [$this->stopStep()];
        $handler = new RecordingHandler();

        $this->watch([$client], $handler);

        self::assertSame(['<m1@example.com>', '<m2@example.com>'], $handler->handled);
        self::assertSame(['open', 'examine INBOX', 'searchSince 2026-11-05', 'fetchHeaders 11,12', 'fetch 11', 'fetch 12', 'idle 1740', 'logout'], $client->calls);
        self::assertSame([self::DSN], $this->factoryDsns);
    }

    public function testAMessageTheHandlerDeclinesIsNeverFetchedWhole(): void
    {
        $client = new FakeImapClient([11 => $this->mail(1), 12 => $this->mail(2)]);
        $client->idleSteps = [$this->stopStep()];
        $handler = new RecordingHandler(declined: ['<m1@example.com>']);

        $this->watch([$client], $handler);

        self::assertSame(['<m2@example.com>'], $handler->handled);
        self::assertNotContains('fetch 11', $client->calls);
        self::assertContains('debug: Message 11 (<m1@example.com>) of answers declined by the handler.', $this->logger->lines());
    }

    public function testNewMailAnnouncedDuringIdleIsFetchedByUid(): void
    {
        $client = new FakeImapClient([11 => $this->mail(1)]);
        $client->idleSteps = [
            function (FakeImapClient $client): bool {
                $client->messages[12] = $this->mail(2);

                return true;
            },
            $this->stopStep(),
        ];
        $handler = new RecordingHandler();

        $this->watch([$client], $handler);

        self::assertSame(['<m1@example.com>', '<m2@example.com>'], $handler->handled);
        self::assertSame(['idle 1740', 'searchUidsFrom 12', 'fetchHeaders 12', 'fetch 12', 'idle 1740', 'logout'], \array_slice($client->calls, 5));
    }

    public function testIdleIsIssuedAgainWhenItsTimeRunsOut(): void
    {
        $client = new FakeImapClient();
        $client->idleSteps = [static fn (): bool => false, static fn (): bool => false, $this->stopStep()];

        $this->watch([$client], new RecordingHandler(), new Mailbox('answers', self::DSN, idleSeconds: 600));

        self::assertSame(['open', 'examine INBOX', 'searchSince 2026-11-05', 'idle 600', 'idle 600', 'idle 600', 'logout'], $client->calls);
    }

    public function testItReconnectsAfterADropAndOffersOnlyMailItHasNotSeen(): void
    {
        $first = new FakeImapClient([11 => $this->mail(1)]);
        $first->idleSteps = [static fn (): bool => throw new ImapConnectionException('The server closed the connection.')];
        $second = new FakeImapClient([11 => $this->mail(1), 12 => $this->mail(2)]);
        $second->idleSteps = [$this->stopStep()];
        $handler = new RecordingHandler();

        $this->watch([$first, $second], $handler);

        self::assertTrue($first->closed);
        self::assertSame(['<m1@example.com>', '<m2@example.com>'], $handler->handled);
        self::assertSame(['<m1@example.com>', '<m2@example.com>'], $handler->offered);
        self::assertContains('fetchHeaders 12', $second->calls);
        self::assertSame('2026-11-08 13:00:01', $this->clock->now()->format('Y-m-d H:i:s'));
        self::assertContains('warning: Lost mailbox answers: The server closed the connection.; reconnecting in 1 s.', $this->logger->lines());
    }

    public function testANewUidValidityStartsTheCatchUpAfresh(): void
    {
        $first = new FakeImapClient([11 => $this->mail(1)], uidValidity: 100);
        $first->idleSteps = [static fn (): bool => throw new ImapConnectionException('dropped')];
        $second = new FakeImapClient([3 => $this->mail(3)], uidValidity: 200);
        $second->idleSteps = [$this->stopStep()];
        $handler = new RecordingHandler();

        $this->watch([$first, $second], $handler);

        self::assertSame(['<m1@example.com>', '<m3@example.com>'], $handler->handled);
    }

    public function testTheReconnectDelayDoublesUpToItsCap(): void
    {
        $clients = [];
        for ($i = 0; $i < 11; ++$i) {
            $clients[] = $client = new FakeImapClient();
            $client->openFailure = new ImapCommandFailedException('AUTHENTICATE failed: NO [AUTHENTICATIONFAILED] Authentication failed.');
        }
        $last = new FakeImapClient();
        $last->idleSteps = [$this->stopStep()];
        $clients[] = $last;

        $this->watch($clients, new RecordingHandler());

        $delays = array_map(
            static fn (array $record): mixed => $record['context']['seconds'] ?? null,
            array_values(array_filter($this->logger->records, static fn (array $record): bool => 'warning' === $record['level'])),
        );
        self::assertSame([1, 2, 4, 8, 16, 32, 64, 128, 256, 300, 300], $delays);
    }

    public function testAHandlerExceptionIsLoggedAndTheNextMessageStillHandled(): void
    {
        $client = new FakeImapClient([11 => $this->mail(1), 12 => $this->mail(2), 13 => $this->mail(3)]);
        $client->idleSteps = [$this->stopStep()];
        $handler = new RecordingHandler(failing: ['<m1@example.com>'], failingWants: ['<m2@example.com>']);

        $this->watch([$client], $handler);

        self::assertSame(['<m3@example.com>'], $handler->handled);
        self::assertContains('error: The handler of answers failed on message 11 (<m1@example.com>).', $this->logger->lines());
        self::assertContains('error: The handler of answers failed on message 12 (<m2@example.com>).', $this->logger->lines());
    }

    public function testAStopEndsTheCatchUpAndLogsOut(): void
    {
        $client = new FakeImapClient([11 => $this->mail(1), 12 => $this->mail(2)]);
        $handler = new class extends \ArrayObject implements IncomingMailHandlerInterface {
            public ?\Closure $onHandle = null;

            public function wants(MessageHeaders $headers): bool
            {
                return true;
            }

            public function handle(Message $message): void
            {
                $this->append($message->headers->messageId);
                ($this->onHandle ?? static fn () => null)();
            }
        };
        $handler->onHandle = function (): void {
            $this->stop = true;
        };

        $this->watch([$client], $handler);

        self::assertSame(['<m1@example.com>'], $handler->getArrayCopy());
        self::assertSame('logout', end($client->calls));
    }

    public function testAMessageOverTheSizeLimitIsSkippedUnfetched(): void
    {
        $client = new FakeImapClient([11 => $this->mail(1), 12 => $this->mail(2)]);
        $client->sizes[11] = 20000000;
        $client->idleSteps = [$this->stopStep()];
        $handler = new RecordingHandler();

        $this->watch([$client], $handler);

        self::assertSame(['<m2@example.com>'], $handler->handled);
        self::assertSame(['<m2@example.com>'], $handler->offered);
        self::assertNotContains('fetch 11', $client->calls);
        self::assertContains('warning: Skipped message 11 (<m1@example.com>) of answers: 20000000 bytes is over the limit of 10485760.', $this->logger->lines());
    }

    public function testAMessageGoneBeforeItsFetchIsPassedOver(): void
    {
        $client = new FakeImapClient([11 => $this->mail(1), 12 => $this->mail(2)]);
        $client->idleSteps = [$this->stopStep()];
        $handler = new class implements IncomingMailHandlerInterface {
            /** @var list<string> */
            public array $handled = [];
            public ?FakeImapClient $client = null;

            public function wants(MessageHeaders $headers): bool
            {
                if ('<m1@example.com>' === $headers->messageId && null !== $this->client) {
                    unset($this->client->messages[11]);
                }

                return true;
            }

            public function handle(Message $message): void
            {
                $this->handled[] = $message->headers->messageId;
            }
        };
        $handler->client = $client;

        $this->watch([$client], $handler);

        self::assertSame(['<m2@example.com>'], $handler->handled);
    }

    public function testWithoutIdleItChecksEveryPollInterval(): void
    {
        $client = new FakeImapClient([11 => $this->mail(1)]);
        $client->idleSupported = false;
        $handler = new RecordingHandler();

        $this->watcher([$client])->watch(new Mailbox('answers', self::DSN, pollSeconds: 120), $handler, function () use ($client, $handler): bool {
            if (\in_array('noop', $client->calls, true)) {
                $client->messages[12] = $this->mail(2);
            }

            return 2 === \count($handler->handled);
        });

        self::assertSame(['<m1@example.com>', '<m2@example.com>'], $handler->handled);
        self::assertSame(['noop', 'searchUidsFrom 12', 'noop', 'searchUidsFrom 12', 'fetchHeaders 12', 'fetch 12', 'logout'], \array_slice($client->calls, 5));
        self::assertSame('2026-11-08 13:04:00', $this->clock->now()->format('Y-m-d H:i:s'));
        self::assertContains('info: The server of answers has no IDLE; checking every 120 s.', $this->logger->lines());
        self::assertCount(1, array_filter($this->logger->lines(), static fn (string $line): bool => str_contains($line, 'has no IDLE')));
    }

    public function testTheLogsCarryNoSecretNoAddressNoSubjectAndNoBody(): void
    {
        $first = new FakeImapClient([11 => $this->mail(1)]);
        $first->idleSteps = [static fn (): bool => throw new ImapConnectionException('dropped')];
        $second = new FakeImapClient([11 => $this->mail(1), 12 => $this->mail(2)]);
        $second->idleSteps = [$this->stopStep()];

        $this->watch([$first, $second], new RecordingHandler(failing: ['<m2@example.com>']));

        $logs = implode("\n", $this->logger->lines()).json_encode(array_map(static fn (array $record): array => array_filter($record['context'], 'is_scalar'), $this->logger->records));
        self::assertNotSame('', $logs);
        foreach (['s3cret', 'answers%40example.org', 'jane@example.com', 'Your enquiry', 'Body of'] as $secret) {
            self::assertStringNotContainsString($secret, $logs);
        }
    }

    /** @var list<string> */
    private array $factoryDsns = [];

    /**
     * @param list<FakeImapClient> $clients
     */
    private function watch(array $clients, IncomingMailHandlerInterface $handler, ?Mailbox $mailbox = null): void
    {
        $this->watcher($clients)->watch($mailbox ?? new Mailbox('answers', self::DSN), $handler, fn (): bool => $this->stop);
    }

    /**
     * @param list<FakeImapClient> $clients
     */
    private function watcher(array $clients): MailboxWatcherService
    {
        $factory = new FakeImapClientFactory($clients);
        $this->factoryDsns = &$factory->dsns;

        return new MailboxWatcherService($factory, new MessageReaderService(), $this->clock, $this->logger);
    }

    /**
     * @return \Closure(FakeImapClient): bool
     */
    private function stopStep(): \Closure
    {
        return function (): bool {
            $this->stop = true;

            return false;
        };
    }

    private function mail(int $number): string
    {
        return "Message-ID: <m{$number}@example.com>\r\nFrom: Jane Doe <jane@example.com>\r\nTo: answers@example.org\r\nSubject: Re: Your enquiry {$number}\r\n\r\nBody of mail {$number}\r\n";
    }
}
