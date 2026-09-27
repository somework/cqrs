<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Kernel;

use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\SomeWorkCqrsBundle;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskArchivedHandler;
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
 * An outbox-only application: the transactional outbox with "transports.command_async" and
 * "transports.event_async", but no async bus (the relay sends on the synchronous buses).
 */
final class OutboxOnlyTestKernel extends Kernel
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
                    'event.bus' => null,
                ],
                'transports' => [
                    'commands' => 'in-memory://?serialize=true',
                    'events' => 'in-memory://?serialize=true',
                ],
            ],
        ]);

        $container->extension('somework_cqrs', [
            'buses' => [
                'command' => 'command.bus',
                'event' => 'event.bus',
            ],
            'transports' => [
                'command_async' => ['default' => 'commands'],
                'event_async' => ['default' => 'events'],
            ],
            'outbox' => ['enabled' => true, 'auto_setup' => false],
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
        $services->set(TaskProjectionHandler::class);
        // TaskArchivedEvent carries a bare #[Outbox], and there is no "async" transport.
        $services->set(TaskArchivedHandler::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    public function getCacheDir(): string
    {
        return dirname(__DIR__, 3).'/var/cache/outbox_only/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__, 3).'/var/log/outbox_only';
    }
}
