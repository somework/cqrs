<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Kernel;

use SomeWork\CqrsBundle\SomeWorkCqrsBundle;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\AsyncProjectionHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\FindTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\GenerateReportHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\ListTasksHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskNotificationHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SomeWorkCqrsBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test-secret',
            'http_method_override' => false,
            'test' => true,
            'messenger' => [
                'default_bus' => 'messenger.bus.commands',
                'buses' => [
                    'messenger.bus.commands' => null,
                    'messenger.bus.commands_async' => [
                        'default_middleware' => ['enabled' => true],
                    ],
                    'messenger.bus.queries' => null,
                    'messenger.bus.events' => null,
                    'messenger.bus.events_async' => [
                        'default_middleware' => ['enabled' => true],
                    ],
                ],
            ],
        ]);

        $container->extension('somework_cqrs', [
            'default_bus' => 'messenger.bus.commands',
            'buses' => [
                'command' => 'messenger.bus.commands',
                'command_async' => 'messenger.bus.commands_async',
                'query' => 'messenger.bus.queries',
                'event' => 'messenger.bus.events',
                'event_async' => 'messenger.bus.events_async',
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(TaskRecorder::class);
        $services->set(CreateTaskHandler::class);
        $services->set(GenerateReportHandler::class);
        $services->set(ListTasksHandler::class);
        $services->set(FindTaskHandler::class);
        $services->set(TaskNotificationHandler::class);
        $services->set(AsyncProjectionHandler::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // No routes required for the functional test kernel.
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/cqrs_bundle/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/cqrs_bundle/log';
    }
}
