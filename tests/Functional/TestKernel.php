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

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use UtafitiLabs\ImapBundle\Tests\Double\RecordingHandler;
use UtafitiLabs\ImapBundle\Tests\Double\SelfSignallingClock;
use UtafitiLabs\ImapBundle\UtafitiLabsImapBundle;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string INVALID_ENVIRONMENT = 'invalid';

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new UtafitiLabsImapBundle(),
        ];
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/utafitilabs_imap/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/utafitilabs_imap/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'test' => true,
            'php_errors' => ['log' => true],
        ]);

        $container->parameters()->set('env(IMAP_ANSWERS_DSN)', 'imaps://answers%40example.org:secret@imap.example.com');
        $container->extension('utafitilabs_imap', [
            'mailboxes' => [
                'answers' => [
                    'dsn' => '%env(IMAP_ANSWERS_DSN)%',
                    'handler' => 'app.answers_handler',
                    'folder' => 'Replies',
                ],
                'billing' => [
                    'handler' => 'app.answers_handler',
                ],
            ],
        ]);
        if (self::INVALID_ENVIRONMENT === $this->environment) {
            $container->extension('utafitilabs_imap', ['mailboxes' => ['answers' => ['idle_seconds' => 3600]]]);
        }

        $services = $container->services();
        $services->set('app.answers_handler', RecordingHandler::class);
        $services->set('app.mail_services_consumer', MailServicesConsumer::class)->autowire()->public();
        $services->set('.utafitilabs_imap.clock', SelfSignallingClock::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }
}
