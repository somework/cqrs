<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Kernel;

use Composer\InstalledVersions;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Log\NullLogger;
use SomeWork\CqrsBundle\Contract\EventBusInterface;
use SomeWork\CqrsBundle\SomeWorkCqrsBundle;
use SomeWork\CqrsBundle\Testing\FakeEventBus;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\ArticleRenamedHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\RenameArticleHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\TestDatabase;
use SomeWork\CqrsBundle\Tests\Fixture\Service\EntityWritingMiddleware;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;
use function version_compare;

use const PHP_VERSION_ID;

/**
 * DoctrineBundle with the ORM, the outbox and "doctrine_events": the entities of tests/Fixture/Entity
 * record events that are stored when the entity manager flushes, and relayed to a sync:// transport.
 * The database is in-memory SQLite, or the one of CQRS_TEST_DATABASE_URL. The "fake_event_bus"
 * environment replaces the event bus with FakeEventBus.
 */
final class DoctrineEventsTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new SomeWorkCqrsBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        // Keep the test output free of the default stderr logger.
        $container->services()->set('logger', NullLogger::class);

        $container->extension('framework', [
            'secret' => 'test-secret',
            'http_method_override' => false,
            'test' => true,
            'messenger' => [
                'default_bus' => 'command.bus',
                'buses' => [
                    'command.bus' => ['middleware' => ['doctrine_transaction']],
                    'event.bus' => ['middleware' => [EntityWritingMiddleware::class, 'doctrine_transaction']],
                ],
                'transports' => [
                    'sync' => 'sync://',
                ],
            ],
        ]);

        $container->parameters()->set('cqrs_test.sqlite_url', 'sqlite:///:memory:');
        $orm = [
            'mappings' => [
                'Fixture' => [
                    'type' => 'attribute',
                    'dir' => dirname(__DIR__).'/Entity',
                    'prefix' => 'SomeWork\CqrsBundle\Tests\Fixture\Entity',
                    'is_bundle' => false,
                ],
            ],
        ];
        // DoctrineBundle 3 always uses them; 2.x needs them on PHP 8.4, where symfony/var-exporter 8 has no lazy ghosts.
        if (PHP_VERSION_ID >= 80400 && version_compare((string) InstalledVersions::getVersion('doctrine/doctrine-bundle'), '3.0', '<')) {
            $orm['enable_native_lazy_objects'] = true;
        }
        $container->extension('doctrine', [
            'dbal' => ['url' => '%env(default:cqrs_test.sqlite_url:'.TestDatabase::URL_VARIABLE.')%'],
            'orm' => $orm,
        ]);

        $container->extension('somework_cqrs', [
            'buses' => [
                'command' => 'command.bus',
                'event' => 'event.bus',
            ],
            // The relay handles the stored events in its own process (sync://).
            'transports' => [
                'event_async' => ['default' => 'sync'],
            ],
            'outbox' => ['enabled' => true, 'auto_setup' => false],
            'doctrine_events' => ['enabled' => true],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(EntityWritingMiddleware::class)->public();
        $services->set(RenameArticleHandler::class);
        $services->set(ArticleRenamedHandler::class);

        if ('fake_event_bus' === $this->environment) {
            $services->set(FakeEventBus::class)->public();
            $services->alias(EventBusInterface::class, FakeEventBus::class)->public();
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    public function getCacheDir(): string
    {
        return dirname(__DIR__, 3).'/var/cache/doctrine_events/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__, 3).'/var/log/doctrine_events';
    }
}
