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

namespace UtafitiLabs\ImapBundle\Tests\Functional;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UtafitiLabs\ImapBundle\Command\WatchMailboxCommand;
use UtafitiLabs\ImapBundle\Model\Mailbox;
use UtafitiLabs\ImapBundle\Model\MailboxState;
use UtafitiLabs\ImapBundle\Store\CacheMailboxStateStore;

/**
 * Boots a kernel with FrameworkBundle and the bundle, configured with two
 * mailboxes, and runs imap:watch through the console application.
 */
final class BundleWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function tearDown(): void
    {
        if (self::$booted) {
            $this->pool('cache.app')->clear();
        }
        parent::tearDown();
        restore_exception_handler();
    }

    public function testTheCommandIsRegisteredLazily(): void
    {
        $command = $this->application()->find('imap:watch');

        self::assertInstanceOf(LazyCommand::class, $command);
        self::assertSame('Watches a mailbox and hands its new mail to the configured handler', $command->getDescription());
        self::assertInstanceOf(WatchMailboxCommand::class, $command->getCommand());
    }

    public function testTheCommandKnowsTheConfiguredMailboxes(): void
    {
        [$exitCode, $display] = $this->runWatch(['mailbox' => 'nope']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('the mailboxes are: answers, billing.', $display);
    }

    public function testAMailboxWithoutDsnWaitsUntilSigtermAndExitsCleanly(): void
    {
        if (!\function_exists('posix_kill') || !\function_exists('pcntl_signal')) {
            self::markTestSkipped('ext-pcntl and ext-posix are needed to send the process a signal.');
        }

        [$exitCode, $display] = $this->runWatch(['mailbox' => 'billing']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Mailbox "billing" is off: its DSN is empty. Waiting until stopped.', $display);
    }

    public function testApplicationsAutowireTheReaderTheStripperAndTheWatcher(): void
    {
        self::bootKernel(['debug' => false]);

        self::assertInstanceOf(MailServicesConsumer::class, self::getContainer()->get('app.mail_services_consumer'));
    }

    public function testTheStateOfEachMailboxIsKeptInCacheAppByDefault(): void
    {
        self::bootKernel(['debug' => false]);
        $mailbox = new Mailbox('answers', 'imaps://u:p@imap.example.com', 'Replies');

        $this->consumer()->states->save($mailbox, new MailboxState(100, 11));

        self::assertEquals(new MailboxState(100, 11), (new CacheMailboxStateStore($this->pool('cache.app')))->get($mailbox));
    }

    public function testStatePoolNamesThePoolTheStateIsKeptIn(): void
    {
        self::bootKernel(['debug' => false, 'environment' => TestKernel::STATE_POOL_ENVIRONMENT]);
        $mailbox = new Mailbox('answers', 'imaps://u:p@imap.example.com', 'Replies');

        $this->consumer()->states->save($mailbox, new MailboxState(100, 11));

        self::assertEquals(new MailboxState(100, 11), (new CacheMailboxStateStore($this->pool('app.imap_state')))->get($mailbox));
        self::assertNull((new CacheMailboxStateStore($this->pool('cache.app')))->get($mailbox));
    }

    public function testTheConfigurationRefusesAnIdleOverTwentyNineMinutes(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('idle_seconds');

        self::bootKernel(['debug' => false, 'environment' => TestKernel::INVALID_ENVIRONMENT]);
    }

    private function consumer(): MailServicesConsumer
    {
        $consumer = self::getContainer()->get('app.mail_services_consumer');
        self::assertInstanceOf(MailServicesConsumer::class, $consumer);

        return $consumer;
    }

    private function pool(string $id): CacheItemPoolInterface
    {
        $pool = self::getContainer()->get($id);
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        return $pool;
    }

    private function application(): Application
    {
        $application = new Application(self::bootKernel(['debug' => false]));
        $application->setAutoExit(false);

        return $application;
    }

    /**
     * @param array<string, string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function runWatch(array $arguments): array
    {
        $output = new BufferedOutput();
        $exitCode = $this->application()->run(new ArrayInput(['command' => 'imap:watch', ...$arguments]), $output);

        return [$exitCode, $output->fetch()];
    }
}
