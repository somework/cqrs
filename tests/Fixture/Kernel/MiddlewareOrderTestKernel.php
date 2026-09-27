<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Kernel;

use Doctrine\DBAL\Connection;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Psr\Log\NullLogger;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\MessengerMiddlewareInjector;
use SomeWork\CqrsBundle\SomeWorkCqrsBundle;
use SomeWork\CqrsBundle\Tests\Fixture\OpenTelemetry\RecordingTracerProvider;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\TestDatabase;
use SomeWork\CqrsBundle\Tests\Fixture\Service\CallerContextMiddleware;
use SomeWork\CqrsBundle\Tests\Fixture\Service\FakeDoctrineTransactionMiddleware;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function array_map;
use function array_values;
use function dirname;

/**
 * Every CQRS bus with every middleware of the bundle: OpenTelemetry, causation id, events without
 * handlers, the deduplication lock release (Lock component) and the outbox, whose store skips
 * Doctrine's transaction middleware (ORM and DBAL).
 *
 * As a compiler pass, the kernel runs after the passes of every bundle (priority -10000) and
 * records the middleware list of each bus in the "cqrs_test.bus_middleware" parameter.
 */
final class MiddlewareOrderTestKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    public const BUSES = ['command.bus', 'command.async_bus', 'query.bus', 'event.bus', 'event.async_bus'];

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SomeWorkCqrsBundle();
    }

    public function process(ContainerBuilder $container): void
    {
        $snapshot = [];
        foreach (self::BUSES as $busId) {
            $argument = MessengerMiddlewareInjector::findBusDefinition($container, $busId)?->getArgument(0);
            $snapshot[$busId] = $argument instanceof IteratorArgument
                ? array_values(array_map(static fn (mixed $middleware): string => (string) $middleware, $argument->getValues()))
                : null;
        }

        $container->setParameter('cqrs_test.bus_middleware', $snapshot);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        // Keep the test output free of the default stderr logger.
        $container->services()->set('logger', NullLogger::class);

        $container->extension('framework', [
            'secret' => 'test-secret',
            'http_method_override' => false,
            'test' => true,
            'lock' => 'in-memory',
            'messenger' => [
                'default_bus' => 'command.bus',
                'buses' => [
                    'command.bus' => ['middleware' => ['doctrine_transaction', CallerContextMiddleware::class]],
                    'command.async_bus' => null,
                    'query.bus' => null,
                    'event.bus' => null,
                    // DoctrineBridge 8.2's middleware that needs no ORM.
                    'event.async_bus' => ['middleware' => ['doctrine_dbal_transaction']],
                ],
                'transports' => [
                    'async' => 'in-memory://',
                ],
            ],
        ]);

        $container->extension('somework_cqrs', [
            'buses' => [
                'command' => 'command.bus',
                'command_async' => 'command.async_bus',
                'query' => 'query.bus',
                'event' => 'event.bus',
                'event_async' => 'event.async_bus',
            ],
            'outbox' => ['enabled' => true, 'auto_setup' => false],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set('doctrine.dbal.default_connection', Connection::class)
            ->factory([TestDatabase::class, 'connect']);
        $services->set(RecordingTracerProvider::class);
        $services->alias(TracerProviderInterface::class, RecordingTracerProvider::class);
        $services->set(CallerContextMiddleware::class);
        // What DoctrineBundle registers.
        $services->set('messenger.middleware.doctrine_transaction', FakeDoctrineTransactionMiddleware::class)->abstract();
        $services->set('messenger.middleware.doctrine_dbal_transaction', FakeDoctrineTransactionMiddleware::class)->abstract();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    public function getCacheDir(): string
    {
        return dirname(__DIR__, 3).'/var/cache/middleware_order/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__, 3).'/var/log/middleware_order';
    }
}
