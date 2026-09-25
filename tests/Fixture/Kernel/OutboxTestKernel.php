<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Kernel;

use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\SomeWorkCqrsBundle;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\AsyncTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskAuditTrailHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskProjectionHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\TestDatabase;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;

/**
 * The async setup of AsyncTransportTestKernel plus the transactional outbox on an in-memory
 * SQLite connection (registered like DoctrineBundle names it, without DoctrineBundle).
 */
final class OutboxTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
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
                    'command.bus' => null,
                    'command.async_bus' => null,
                    'event.bus' => null,
                    'event.async_bus' => null,
                ],
                'transports' => [
                    'async' => 'in-memory://?serialize=true',
                ],
            ],
        ]);

        $container->extension('somework_cqrs', [
            'buses' => [
                'command' => 'command.bus',
                'command_async' => 'command.async_bus',
                'event' => 'event.bus',
                'event_async' => 'event.async_bus',
            ],
            'transports' => [
                'command_async' => ['default' => 'async'],
                'event_async' => ['default' => 'async'],
            ],
            'outbox' => ['enabled' => true, 'auto_setup' => false, 'max_attempts' => 2],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        // In-memory SQLite, or the database of CQRS_TEST_DATABASE_URL.
        $services->set('doctrine.dbal.default_connection', Connection::class)
            ->factory([TestDatabase::class, 'connect'])
            ->public();
        $services->set(TaskRecorder::class)->public();
        // Private and unused otherwise, so the test container would not have it.
        $services->alias('test.outbox_writer', OutboxWriter::class)->public();
        $services->set(CreateTaskHandler::class);
        $services->set(AsyncTaskHandler::class);
        $services->set(TaskAuditTrailHandler::class);
        $services->set(TaskProjectionHandler::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    public function getCacheDir(): string
    {
        return dirname(__DIR__, 3).'/var/cache/outbox/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__, 3).'/var/log/outbox';
    }
}
