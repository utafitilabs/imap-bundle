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

namespace UtafitiLabs\ImapBundle;

use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use UtafitiLabs\ImapBundle\Command\WatchMailboxCommand;
use UtafitiLabs\ImapBundle\Factory\ImapClientFactory;
use UtafitiLabs\ImapBundle\Service\MailboxWatcherService;
use UtafitiLabs\ImapBundle\Service\MessageReaderService;
use UtafitiLabs\ImapBundle\Service\QuotedReplyStripperService;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;

/**
 * Read-only IMAP mailbox watching: the imap:watch command, the watcher, the
 * message reader and the quoted-reply stripper.
 *
 * Configuration and services are declared on the bundle class, as "Bundles
 * extending the AbstractBundle class … can define the configuration, load
 * services and prepend config" in one place
 * (https://symfony.com/doc/current/bundles/configuration.html). Services are
 * explicit, prefixed with the alias and private, with class aliases for the
 * ones an application uses, per
 * https://symfony.com/doc/current/bundles/best_practices.html#services; the
 * command is tagged console.command and its logger tagged monolog.logger as
 * vendor/symfony/framework-bundle/Resources/config/console.php does for core's.
 */
final class UtafitiLabsImapBundle extends AbstractBundle
{
    protected string $extensionAlias = 'utafitilabs_imap';

    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $root */
        $root = $definition->rootNode();
        $mailbox = $root->children()
            ->arrayNode('mailboxes')
            ->info('The watched mailboxes by name; run one "imap:watch <name>" process per mailbox.')
            ->useAttributeAsKey('name')
            ->arrayPrototype()
            ->children();

        $mailbox->scalarNode('dsn')
            ->info('imaps://user:password@host[:993] or imap://user:password@host[:143] (STARTTLS), credentials URL-encoded; empty turns the mailbox off.')
            ->defaultValue('');
        $mailbox->scalarNode('handler')
            ->info('The id of the service implementing IncomingMailHandlerInterface.')
            ->isRequired()
            ->cannotBeEmpty();
        $mailbox->scalarNode('folder')->defaultValue('INBOX')->cannotBeEmpty();
        $mailbox->integerNode('catch_up_days')
            ->info('On every (re)connect, the mail of this many days back is offered to the handler again.')
            ->defaultValue(3)->min(0)->max(365);
        $mailbox->integerNode('idle_seconds')
            ->info('IDLE is renewed after this many seconds; servers may drop an IDLE after 30 minutes (RFC 2177).')
            ->defaultValue(1740)->min(60)->max(1740);
        $mailbox->integerNode('poll_seconds')
            ->info('How often a server without IDLE is checked.')
            ->defaultValue(300)->min(10);
        $mailbox->integerNode('max_message_bytes')
            ->info('Larger messages are skipped without being fetched.')
            ->defaultValue(10485760)->min(1024);
    }

    /**
     * @param array{mailboxes: array<string, array{dsn: string, handler: string, folder: string, catch_up_days: int, idle_seconds: int, poll_seconds: int, max_message_bytes: int}>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set('.utafitilabs_imap.clock', NativeClock::class);

        $services->set('utafitilabs_imap.message_reader', MessageReaderService::class);
        $services->alias(MessageReaderService::class, 'utafitilabs_imap.message_reader');

        $services->set('utafitilabs_imap.quoted_reply_stripper', QuotedReplyStripperService::class);
        $services->alias(QuotedReplyStripperService::class, 'utafitilabs_imap.quoted_reply_stripper');

        $services->set('.utafitilabs_imap.client_factory', ImapClientFactory::class)
            ->args([service('.utafitilabs_imap.clock')]);

        $services->set('utafitilabs_imap.mailbox_watcher', MailboxWatcherService::class)
            ->args([
                service('.utafitilabs_imap.client_factory'),
                service('utafitilabs_imap.message_reader'),
                service('.utafitilabs_imap.clock'),
                service('logger')->nullOnInvalid(),
            ])
            ->tag('monolog.logger', ['channel' => 'imap']);
        $services->alias(MailboxWatcherService::class, 'utafitilabs_imap.mailbox_watcher');

        $handlers = [];
        foreach ($config['mailboxes'] as $name => $mailbox) {
            $handlers[$name] = service($mailbox['handler']);
        }

        $services->set('utafitilabs_imap.command.watch', WatchMailboxCommand::class)
            ->args([
                $config['mailboxes'],
                service_locator($handlers),
                service('utafitilabs_imap.mailbox_watcher'),
                service('.utafitilabs_imap.clock'),
            ])
            ->tag('console.command');
    }
}
