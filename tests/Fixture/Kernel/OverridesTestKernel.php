<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Kernel;

use Psr\Log\NullLogger;
use SomeWork\CqrsBundle\Policy\ExponentialBackoffRetryPolicy;
use SomeWork\CqrsBundle\SomeWorkCqrsBundle;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\ListTasksHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ListTasksQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;

/**
 * Kernel exercising per-message configuration overrides through a real, compiled container.
 */
final class OverridesTestKernel extends Kernel
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
                'transports' => ['async' => 'in-memory://'],
            ],
            'rate_limiter' => [
                'list_tasks' => [
                    'policy' => 'fixed_window',
                    'limit' => 1,
                    'interval' => '1 minute',
                    // In-memory storage: every kernel boot starts with a fresh limit.
                    'storage_service' => InMemoryStorage::class,
                    'lock_factory' => null,
                ],
            ],
        ]);

        $container->extension('somework_cqrs', [
            'retry_policies' => [
                'command' => [
                    'map' => [CreateTaskCommand::class => ExponentialBackoffRetryPolicy::class],
                ],
            ],
            'async' => [
                'dispatch_after_current_bus' => [
                    'command' => [
                        'map' => [CreateTaskCommand::class => false],
                    ],
                ],
            ],
            'retry_strategy' => [
                'transports' => ['async' => 'command'],
                'max_delay' => 60000,
            ],
            'rate_limiting' => [
                'query' => [
                    'map' => [ListTasksQuery::class => 'list_tasks'],
                ],
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(InMemoryStorage::class);
        $services->set(TaskRecorder::class)->public();
        $services->set(CreateTaskHandler::class);
        $services->set(ListTasksHandler::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    public function getCacheDir(): string
    {
        return dirname(__DIR__, 3).'/var/cache/overrides_kernel/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__, 3).'/var/log/overrides_kernel';
    }
}
