<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Kernel;

use SomeWork\CqrsBundle\SomeWorkCqrsBundle;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskNotificationHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Service\RecordingLogger;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;

/**
 * A sync and an async event bus, and one event handler pinned to the sync bus
 * (TaskNotificationHandler): the async bus has no handler for TaskCreatedEvent. Logs to a
 * RecordingLogger.
 */
final class SyncPinnedEventTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SomeWorkCqrsBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->services()->set('logger', RecordingLogger::class)->public();

        $container->extension('framework', [
            'secret' => 'test-secret',
            'http_method_override' => false,
            'test' => true,
            'messenger' => [
                'default_bus' => 'messenger.bus.commands',
                'buses' => [
                    'messenger.bus.commands' => null,
                    'messenger.bus.events' => null,
                    'messenger.bus.events_async' => null,
                ],
            ],
        ]);

        $container->extension('somework_cqrs', [
            'default_bus' => 'messenger.bus.commands',
            'buses' => [
                'command' => 'messenger.bus.commands',
                'event' => 'messenger.bus.events',
                'event_async' => 'messenger.bus.events_async',
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(TaskRecorder::class);
        $services->set(TaskNotificationHandler::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    public function getCacheDir(): string
    {
        return dirname(__DIR__, 3).'/var/cache/sync_pinned_event_kernel/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__, 3).'/var/log/sync_pinned_event_kernel';
    }
}
