<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Kernel;

use Psr\Log\NullLogger;
use SomeWork\CqrsBundle\SomeWorkCqrsBundle;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\InterfaceOnlyCommandHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\ListTasksHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskAuditTrailHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskProjectionHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;

/**
 * Kernel mirroring a fresh installation: Messenger with its single default bus
 * and no "somework_cqrs" configuration at all.
 */
final class MinimalTestKernel extends Kernel
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
            'messenger' => [],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(TaskRecorder::class)->public();
        $services->set(CreateTaskHandler::class);
        $services->set(ListTasksHandler::class);
        $services->set(InterfaceOnlyCommandHandler::class);
        $services->set(TaskAuditTrailHandler::class);
        $services->set(TaskProjectionHandler::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    public function getCacheDir(): string
    {
        return dirname(__DIR__, 3).'/var/cache/minimal_kernel/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__, 3).'/var/log/minimal_kernel';
    }
}
