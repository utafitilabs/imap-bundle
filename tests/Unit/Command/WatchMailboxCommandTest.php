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

namespace UtafitiLabs\ImapBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use UtafitiLabs\ImapBundle\Command\WatchMailboxCommand;
use UtafitiLabs\ImapBundle\Service\MailboxWatcherService;
use UtafitiLabs\ImapBundle\Service\MessageReaderService;
use UtafitiLabs\ImapBundle\Tests\Double\FakeImapClient;
use UtafitiLabs\ImapBundle\Tests\Double\FakeImapClientFactory;
use UtafitiLabs\ImapBundle\Tests\Double\RecordingHandler;
use UtafitiLabs\ImapBundle\Tests\Double\SignallingClock;

final class WatchMailboxCommandTest extends TestCase
{
    private const array MAILBOX = ['dsn' => '', 'folder' => 'INBOX', 'catch_up_days' => 3, 'idle_seconds' => 1740, 'poll_seconds' => 300, 'max_message_bytes' => 10485760];

    private ?WatchMailboxCommand $command = null;

    public function testAMailboxWithAnEmptyDsnIsOffAndWaitsUntilStopped(): void
    {
        $clock = new SignallingClock(3600.0, function (): void {
            $this->command?->handleSignal(\defined('SIGTERM') ? \SIGTERM : 15);
        });
        $tester = $this->tester(['answers' => self::MAILBOX], new FakeImapClientFactory([]), $clock);

        self::assertSame(Command::SUCCESS, $tester->execute(['mailbox' => 'answers']));
        self::assertStringContainsString('Mailbox "answers" is off: its DSN is empty. Waiting until stopped.', $tester->getDisplay());
        self::assertSame(3600.0, $clock->slept());
    }

    public function testItWatchesTheMailboxUntilASignalStopsIt(): void
    {
        $client = new FakeImapClient([5 => "Message-ID: <m5@example.com>\r\n\r\nHello\r\n"]);
        $client->idleSteps = [function (): bool {
            $this->command?->handleSignal(\defined('SIGINT') ? \SIGINT : 2);

            return false;
        }];
        $factory = new FakeImapClientFactory([$client]);
        $handler = new RecordingHandler();
        $tester = $this->tester(['answers' => ['dsn' => 'imaps://u:secret@imap.example.com', 'folder' => 'Replies'] + self::MAILBOX], $factory, new SignallingClock(1e9, static fn () => null), $handler);

        self::assertSame(Command::SUCCESS, $tester->execute(['mailbox' => 'answers']));
        self::assertSame(['<m5@example.com>'], $handler->handled);
        self::assertSame(['imaps://u:secret@imap.example.com'], $factory->dsns);
        self::assertContains('examine Replies', $client->calls);
        self::assertStringContainsString('Watching mailbox "answers" (Replies).', $tester->getDisplay());
        self::assertStringContainsString('Stopped watching mailbox "answers".', $tester->getDisplay());
        self::assertStringNotContainsString('secret', $tester->getDisplay());
    }

    public function testAnUnknownMailboxNamesTheConfiguredOnes(): void
    {
        $tester = $this->tester(['answers' => self::MAILBOX, 'billing' => self::MAILBOX], new FakeImapClientFactory([]), new SignallingClock(1e9, static fn () => null));

        self::assertSame(Command::INVALID, $tester->execute(['mailbox' => 'nope']));
        self::assertStringContainsString('No mailbox "nope" is configured; the mailboxes are: answers, billing.', $tester->getDisplay());
    }

    public function testItListensForTheStopSignals(): void
    {
        if (!\defined('SIGTERM')) {
            self::markTestSkipped('ext-pcntl is not loaded.');
        }
        $this->tester([], new FakeImapClientFactory([]), new SignallingClock(1e9, static fn () => null));

        self::assertSame([\SIGINT, \SIGTERM], $this->command?->getSubscribedSignals());
        self::assertFalse($this->command->handleSignal(\SIGTERM), 'The command ends by itself, after logging out.');
    }

    /**
     * @param array<string, array<string, mixed>> $mailboxes
     */
    private function tester(array $mailboxes, FakeImapClientFactory $factory, SignallingClock $clock, ?RecordingHandler $handler = null): CommandTester
    {
        $handler ??= new RecordingHandler();
        $locator = new ServiceLocator(array_map(static fn (): \Closure => static fn (): RecordingHandler => $handler, $mailboxes));
        $this->command = new WatchMailboxCommand($mailboxes, $locator, new MailboxWatcherService($factory, new MessageReaderService(), $clock), $clock);

        return new CommandTester($this->command);
    }
}
