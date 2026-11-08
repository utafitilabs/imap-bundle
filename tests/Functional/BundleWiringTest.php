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

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UtafitiLabs\ImapBundle\Command\WatchMailboxCommand;

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

    public function testTheConfigurationRefusesAnIdleOverTwentyNineMinutes(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('idle_seconds');

        self::bootKernel(['debug' => false, 'environment' => TestKernel::INVALID_ENVIRONMENT]);
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
