<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Kernel;

use SomeWork\CqrsBundle\SomeWorkCqrsBundle;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\AttributeOnlyCommandHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\AttributeOnlyEventHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\AttributeOnlyQueryHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\FindTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskNotificationHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Test kernel that registers both interface-based and attribute-only handlers.
 * Used to verify attribute-only handler discovery in a full container compilation.
 */
final class AttributeOnlyTestKernel extends Kernel
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
                    'messenger.bus.queries' => null,
                    'messenger.bus.events' => null,
                ],
            ],
        ]);

        $container->extension('somework_cqrs', [
            'default_bus' => 'messenger.bus.commands',
            'buses' => [
                'command' => 'messenger.bus.commands',
                'query' => 'messenger.bus.queries',
                'event' => 'messenger.bus.events',
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        // Shared services
        $services->set(TaskRecorder::class);

        // Interface-based handlers (existing)
        $services->set(CreateTaskHandler::class);
        $services->set(FindTaskHandler::class);
        $services->set(TaskNotificationHandler::class);

        // Attribute-only handlers (no marker interfaces)
        $services->set(AttributeOnlyCommandHandler::class);
        $services->set(AttributeOnlyQueryHandler::class);
        $services->set(AttributeOnlyEventHandler::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/cqrs_bundle_attr_only/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/cqrs_bundle_attr_only/log';
    }
}
