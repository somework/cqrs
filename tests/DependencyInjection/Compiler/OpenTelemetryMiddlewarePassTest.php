<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use OpenTelemetry\API\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OpenTelemetryMiddlewarePass;
use SomeWork\CqrsBundle\Messenger\OpenTelemetryMiddleware;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

#[CoversClass(OpenTelemetryMiddlewarePass::class)]
final class OpenTelemetryMiddlewarePassTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!interface_exists(TracerProviderInterface::class)) {
            self::markTestSkipped('open-telemetry/api is not installed.');
        }
    }

    public function test_does_nothing_when_tracer_provider_not_in_container(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.default_bus', 'messenger.bus.default');

        $busDefinition = new Definition();
        $busDefinition->setArgument(0, new IteratorArgument([new Reference('existing.middleware')]));
        $container->setDefinition('messenger.bus.default', $busDefinition);

        (new OpenTelemetryMiddlewarePass())->process($container);

        self::assertFalse($container->hasDefinition('somework_cqrs.messenger.middleware.open_telemetry'));
    }

    public function test_registers_middleware_and_prepends_to_all_buses(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.default_bus', 'messenger.bus.default');
        $container->setParameter('somework_cqrs.bus.command', 'messenger.bus.commands');
        $container->setParameter('somework_cqrs.bus.event', 'messenger.bus.events');

        // Register a TracerProviderInterface service
        $container->setDefinition(TracerProviderInterface::class, new Definition(TracerProviderInterface::class));

        $defaultBusDef = new Definition();
        $defaultBusDef->setArgument(0, new IteratorArgument([new Reference('existing.middleware')]));
        $container->setDefinition('messenger.bus.default', $defaultBusDef);

        $commandBusDef = new Definition();
        $commandBusDef->setArgument(0, new IteratorArgument([new Reference('other.middleware')]));
        $container->setDefinition('messenger.bus.commands', $commandBusDef);

        $eventBusDef = new Definition();
        $eventBusDef->setArgument(0, new IteratorArgument([new Reference('event.middleware')]));
        $container->setDefinition('messenger.bus.events', $eventBusDef);

        (new OpenTelemetryMiddlewarePass())->process($container);

        // Middleware definition should be registered
        self::assertTrue($container->hasDefinition('somework_cqrs.messenger.middleware.open_telemetry'));

        $middlewareDefinition = $container->getDefinition('somework_cqrs.messenger.middleware.open_telemetry');
        self::assertSame(OpenTelemetryMiddleware::class, $middlewareDefinition->getClass());

        // Verify middleware is prepended to all buses
        foreach (['messenger.bus.default', 'messenger.bus.commands', 'messenger.bus.events'] as $busId) {
            /** @var IteratorArgument $argument */
            $argument = $container->findDefinition($busId)->getArgument(0);
            $middlewareRefs = $argument->getValues();

            self::assertSame(
                'somework_cqrs.messenger.middleware.open_telemetry',
                (string) $middlewareRefs[0],
                sprintf('OTel middleware should be prepended to %s', $busId),
            );
        }
    }

    public function test_does_not_duplicate_middleware_on_second_pass(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.default_bus', 'messenger.bus.default');
        $container->setDefinition(TracerProviderInterface::class, new Definition(TracerProviderInterface::class));

        $busDef = new Definition();
        $busDef->setArgument(0, new IteratorArgument([new Reference('existing.middleware')]));
        $container->setDefinition('messenger.bus.default', $busDef);

        $pass = new OpenTelemetryMiddlewarePass();
        $pass->process($container);
        $pass->process($container);

        /** @var IteratorArgument $argument */
        $argument = $container->findDefinition('messenger.bus.default')->getArgument(0);

        self::assertCount(2, $argument->getValues());
    }

    public function test_resolves_traceable_bus_inner_definition(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.default_bus', 'messenger.bus.default');
        $container->setDefinition(TracerProviderInterface::class, new Definition(TracerProviderInterface::class));

        $innerDefinition = new Definition();
        $innerDefinition->setArgument(0, new IteratorArgument([new Reference('existing.middleware')]));
        $container->setDefinition('debug.traced.messenger.bus.default.inner', $innerDefinition);

        $traceableDefinition = new Definition();
        $traceableDefinition->setArgument(0, new Reference('debug.traced.messenger.bus.default.inner'));
        $container->setDefinition('debug.traced.messenger.bus.default', $traceableDefinition);

        $container->setAlias('messenger.bus.default', 'debug.traced.messenger.bus.default');

        (new OpenTelemetryMiddlewarePass())->process($container);

        /** @var IteratorArgument $argument */
        $argument = $container->findDefinition('debug.traced.messenger.bus.default.inner')->getArgument(0);

        $middlewareRefs = $argument->getValues();
        self::assertCount(2, $middlewareRefs);
        self::assertSame(
            'somework_cqrs.messenger.middleware.open_telemetry',
            (string) $middlewareRefs[0],
        );
    }
}
