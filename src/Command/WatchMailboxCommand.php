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

namespace UtafitiLabs\ImapBundle\Command;

use Psr\Container\ContainerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use UtafitiLabs\ImapBundle\Model\Mailbox;
use UtafitiLabs\ImapBundle\Service\IncomingMailHandlerInterface;
use UtafitiLabs\ImapBundle\Service\MailboxWatcherService;

/**
 * Watches one configured mailbox until SIGINT or SIGTERM. A mailbox whose DSN
 * is empty is off: the command says so and waits for the stop signal instead
 * of exiting, so a process supervisor or container does not restart it in a
 * loop.
 *
 * A signal sets the stop flag and lets the watcher log out, as Messenger's
 * messenger:consume stops its worker
 * (vendor/symfony/messenger/Command/ConsumeMessagesCommand.php); the console
 * Application installs the handlers with pcntl_async_signals when ext-pcntl is
 * loaded (vendor/symfony/console/SignalRegistry/SignalRegistry.php).
 *
 * @see https://symfony.com/doc/current/components/console/events.html#console-signal
 */
#[AsCommand(name: 'imap:watch', description: 'Watches a mailbox and hands its new mail to the configured handler')]
final class WatchMailboxCommand extends Command implements SignalableCommandInterface
{
    private bool $stopRequested = false;

    /**
     * @param array<string, array<string, mixed>> $mailboxes the configured mailboxes by name
     * @param ContainerInterface                  $handlers  each mailbox's handler by mailbox name
     */
    public function __construct(
        private readonly array $mailboxes,
        private readonly ContainerInterface $handlers,
        private readonly MailboxWatcherService $watcher,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('mailbox', InputArgument::REQUIRED, 'The name of the mailbox under utafitilabs_imap.mailboxes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->stopRequested = false;
        $name = self::string($input->getArgument('mailbox'));

        $config = $this->mailboxes[$name] ?? null;
        if (null === $config) {
            $io->error(\sprintf('No mailbox "%s" is configured; the mailboxes are: %s.', $name, implode(', ', array_keys($this->mailboxes)) ?: 'none'));

            return Command::INVALID;
        }

        $mailbox = new Mailbox(
            $name,
            self::string($config['dsn'] ?? ''),
            self::string($config['folder'] ?? 'INBOX'),
            self::int($config['catch_up_days'] ?? 3),
            self::int($config['idle_seconds'] ?? 1740),
            self::int($config['poll_seconds'] ?? 300),
            self::int($config['max_message_bytes'] ?? 10485760),
        );

        if ('' === trim($mailbox->dsn)) {
            $io->writeln(\sprintf('Mailbox "%s" is off: its DSN is empty. Waiting until stopped.', $name));
            while (!$this->isStopRequested()) {
                $this->clock->sleep(1);
            }

            return Command::SUCCESS;
        }

        $handler = $this->handlers->get($name);
        if (!$handler instanceof IncomingMailHandlerInterface) {
            $io->error(\sprintf('The handler of mailbox "%s" does not implement %s.', $name, IncomingMailHandlerInterface::class));

            return Command::FAILURE;
        }

        $io->writeln(\sprintf('Watching mailbox "%s" (%s).', $name, $mailbox->folder));
        $this->watcher->watch($mailbox, $handler, $this->isStopRequested(...));
        $io->writeln(\sprintf('Stopped watching mailbox "%s".', $name));

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return \defined('SIGTERM') ? [\SIGINT, \SIGTERM] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stopRequested = true;

        return false;
    }

    private function isStopRequested(): bool
    {
        return $this->stopRequested;
    }

    private static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
