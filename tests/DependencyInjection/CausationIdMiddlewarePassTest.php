<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CausationIdMiddlewarePass;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

#[CoversClass(CausationIdMiddlewarePass::class)]
final class CausationIdMiddlewarePassTest extends TestCase
{
    public function test_injects_middleware_into_default_bus(): void
    {
        $container = $this->createContainerWithBuses(['messenger.default_bus']);

        $pass = new CausationIdMiddlewarePass();
        $pass->process($container);

        $busDefinition = $container->getDefinition('messenger.default_bus');
        /** @var IteratorArgument $middlewareArg */
        $middlewareArg = $busDefinition->getArgument(0);
        $middlewares = $middlewareArg->getValues();

        self::assertCount(2, $middlewares);
        self::assertSame('somework_cqrs.messenger.middleware.causation_id', (string) $middlewares[0]);
    }

    public function test_injects_middleware_into_all_configured_buses(): void
    {
        $container = $this->createContainerWithBuses([
            'messenger.default_bus',
            'messenger.bus.command_async',
            'messenger.bus.event_async',
        ]);

        $container->setParameter('somework_cqrs.bus.command_async', 'messenger.bus.command_async');
        $container->setParameter('somework_cqrs.bus.event_async', 'messenger.bus.event_async');

        $pass = new CausationIdMiddlewarePass();
        $pass->process($container);

        foreach (['messenger.default_bus', 'messenger.bus.command_async', 'messenger.bus.event_async'] as $busId) {
            $busDefinition = $container->getDefinition($busId);
            /** @var IteratorArgument $middlewareArg */
            $middlewareArg = $busDefinition->getArgument(0);
            $middlewares = $middlewareArg->getValues();

            self::assertSame(
                'somework_cqrs.messenger.middleware.causation_id',
                (string) $middlewares[0],
                sprintf('CausationIdMiddleware not prepended to bus "%s"', $busId),
            );
        }
    }

    public function test_does_not_duplicate_middleware_on_second_pass(): void
    {
        $container = $this->createContainerWithBuses(['messenger.default_bus']);

        $pass = new CausationIdMiddlewarePass();
        $pass->process($container);
        $pass->process($container);

        $busDefinition = $container->getDefinition('messenger.default_bus');
        /** @var IteratorArgument $middlewareArg */
        $middlewareArg = $busDefinition->getArgument(0);

        $causationRefs = array_filter(
            $middlewareArg->getValues(),
            static fn (Reference $ref): bool => 'somework_cqrs.messenger.middleware.causation_id' === (string) $ref,
        );

        self::assertCount(1, $causationRefs);
    }

    public function test_skips_when_middleware_definition_missing(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.default_bus', 'messenger.default_bus');

        $pass = new CausationIdMiddlewarePass();
        $pass->process($container);

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertTrue(true);
    }

    public function test_skips_when_default_bus_parameter_missing(): void
    {
        $container = new ContainerBuilder();
        $container->register('somework_cqrs.messenger.middleware.causation_id', \stdClass::class);

        $pass = new CausationIdMiddlewarePass();
        $pass->process($container);

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertTrue(true);
    }

    public function test_skips_all_buses_when_disabled(): void
    {
        $container = $this->createContainerWithBuses([
            'messenger.default_bus',
            'messenger.bus.command_async',
        ]);

        $container->setParameter('somework_cqrs.bus.command_async', 'messenger.bus.command_async');
        $container->setParameter('somework_cqrs.causation_id.enabled', false);
        $container->setParameter('somework_cqrs.causation_id.buses', []);

        $pass = new CausationIdMiddlewarePass();
        $pass->process($container);

        foreach (['messenger.default_bus', 'messenger.bus.command_async'] as $busId) {
            $busDefinition = $container->getDefinition($busId);
            /** @var IteratorArgument $middlewareArg */
            $middlewareArg = $busDefinition->getArgument(0);
            $middlewares = $middlewareArg->getValues();

            self::assertCount(1, $middlewares, sprintf('Bus "%s" should NOT have CausationIdMiddleware', $busId));
            self::assertSame(
                'messenger.middleware.some_existing',
                (string) $middlewares[0],
                sprintf('Bus "%s" should only have the original middleware', $busId),
            );
        }
    }

    public function test_injects_into_all_buses_when_enabled_with_empty_buses_array(): void
    {
        $container = $this->createContainerWithBuses([
            'messenger.default_bus',
            'messenger.bus.command_async',
        ]);

        $container->setParameter('somework_cqrs.bus.command_async', 'messenger.bus.command_async');
        $container->setParameter('somework_cqrs.causation_id.enabled', true);
        $container->setParameter('somework_cqrs.causation_id.buses', []);

        $pass = new CausationIdMiddlewarePass();
        $pass->process($container);

        foreach (['messenger.default_bus', 'messenger.bus.command_async'] as $busId) {
            $busDefinition = $container->getDefinition($busId);
            /** @var IteratorArgument $middlewareArg */
            $middlewareArg = $busDefinition->getArgument(0);
            $middlewares = $middlewareArg->getValues();

            self::assertSame(
                'somework_cqrs.messenger.middleware.causation_id',
                (string) $middlewares[0],
                sprintf('CausationIdMiddleware not prepended to bus "%s"', $busId),
            );
        }
    }

    public function test_injects_only_into_configured_buses(): void
    {
        $container = $this->createContainerWithBuses([
            'messenger.default_bus',
            'messenger.bus.command_async',
            'messenger.bus.event_async',
        ]);

        $container->setParameter('somework_cqrs.bus.command_async', 'messenger.bus.command_async');
        $container->setParameter('somework_cqrs.bus.event_async', 'messenger.bus.event_async');
        $container->setParameter('somework_cqrs.causation_id.enabled', true);
        $container->setParameter('somework_cqrs.causation_id.buses', ['messenger.bus.command_async']);

        $pass = new CausationIdMiddlewarePass();
        $pass->process($container);

        // command_async should have the middleware
        $busDefinition = $container->getDefinition('messenger.bus.command_async');
        /** @var IteratorArgument $middlewareArg */
        $middlewareArg = $busDefinition->getArgument(0);
        $middlewares = $middlewareArg->getValues();
        self::assertSame(
            'somework_cqrs.messenger.middleware.causation_id',
            (string) $middlewares[0],
            'CausationIdMiddleware should be in command_async bus',
        );

        // default_bus and event_async should NOT have the middleware
        foreach (['messenger.default_bus', 'messenger.bus.event_async'] as $busId) {
            $busDefinition = $container->getDefinition($busId);
            /** @var IteratorArgument $middlewareArg */
            $middlewareArg = $busDefinition->getArgument(0);
            $middlewares = $middlewareArg->getValues();

            self::assertCount(1, $middlewares, sprintf('Bus "%s" should NOT have CausationIdMiddleware', $busId));
            self::assertSame(
                'messenger.middleware.some_existing',
                (string) $middlewares[0],
                sprintf('Bus "%s" should only have the original middleware', $busId),
            );
        }
    }

    public function test_backward_compatible_when_parameters_missing(): void
    {
        $container = $this->createContainerWithBuses(['messenger.default_bus']);

        // Do NOT set causation_id parameters — backward compat
        $pass = new CausationIdMiddlewarePass();
        $pass->process($container);

        $busDefinition = $container->getDefinition('messenger.default_bus');
        /** @var IteratorArgument $middlewareArg */
        $middlewareArg = $busDefinition->getArgument(0);
        $middlewares = $middlewareArg->getValues();

        self::assertCount(2, $middlewares);
        self::assertSame('somework_cqrs.messenger.middleware.causation_id', (string) $middlewares[0]);
    }

    public function test_injects_into_multiple_scoped_buses(): void
    {
        $container = $this->createContainerWithBuses([
            'messenger.default_bus',
            'messenger.bus.commands',
            'messenger.bus.events',
            'messenger.bus.queries',
        ]);

        $container->setParameter('somework_cqrs.bus.command', 'messenger.bus.commands');
        $container->setParameter('somework_cqrs.bus.event', 'messenger.bus.events');
        $container->setParameter('somework_cqrs.bus.query', 'messenger.bus.queries');
        $container->setParameter('somework_cqrs.causation_id.enabled', true);
        $container->setParameter('somework_cqrs.causation_id.buses', [
            'messenger.bus.commands',
            'messenger.bus.events',
        ]);

        $pass = new CausationIdMiddlewarePass();
        $pass->process($container);

        // commands and events should have the middleware
        foreach (['messenger.bus.commands', 'messenger.bus.events'] as $busId) {
            $busDefinition = $container->getDefinition($busId);
            /** @var IteratorArgument $middlewareArg */
            $middlewareArg = $busDefinition->getArgument(0);
            $middlewares = $middlewareArg->getValues();

            self::assertSame(
                'somework_cqrs.messenger.middleware.causation_id',
                (string) $middlewares[0],
                sprintf('CausationIdMiddleware should be in bus "%s"', $busId),
            );
        }

        // default_bus and queries should NOT have the middleware
        foreach (['messenger.default_bus', 'messenger.bus.queries'] as $busId) {
            $busDefinition = $container->getDefinition($busId);
            /** @var IteratorArgument $middlewareArg */
            $middlewareArg = $busDefinition->getArgument(0);
            $middlewares = $middlewareArg->getValues();

            self::assertCount(1, $middlewares, sprintf('Bus "%s" should NOT have CausationIdMiddleware', $busId));
            self::assertSame(
                'messenger.middleware.some_existing',
                (string) $middlewares[0],
                sprintf('Bus "%s" should only have the original middleware', $busId),
            );
        }
    }

    public function test_skips_gracefully_when_scoped_bus_not_in_container(): void
    {
        $container = $this->createContainerWithBuses(['messenger.default_bus']);

        $container->setParameter('somework_cqrs.causation_id.enabled', true);
        $container->setParameter('somework_cqrs.causation_id.buses', ['nonexistent.bus']);

        $pass = new CausationIdMiddlewarePass();
        $pass->process($container);

        // default_bus should NOT have the middleware (it's not in the allowed list)
        $busDefinition = $container->getDefinition('messenger.default_bus');
        /** @var IteratorArgument $middlewareArg */
        $middlewareArg = $busDefinition->getArgument(0);
        $middlewares = $middlewareArg->getValues();

        self::assertCount(1, $middlewares);
        self::assertSame('messenger.middleware.some_existing', (string) $middlewares[0]);
    }

    public function test_skips_when_enabled_false_even_with_buses_configured(): void
    {
        $container = $this->createContainerWithBuses([
            'messenger.default_bus',
            'messenger.bus.commands',
        ]);

        $container->setParameter('somework_cqrs.bus.command', 'messenger.bus.commands');
        $container->setParameter('somework_cqrs.causation_id.enabled', false);
        $container->setParameter('somework_cqrs.causation_id.buses', ['messenger.bus.commands']);

        $pass = new CausationIdMiddlewarePass();
        $pass->process($container);

        foreach (['messenger.default_bus', 'messenger.bus.commands'] as $busId) {
            $busDefinition = $container->getDefinition($busId);
            /** @var IteratorArgument $middlewareArg */
            $middlewareArg = $busDefinition->getArgument(0);
            $middlewares = $middlewareArg->getValues();

            self::assertCount(1, $middlewares, sprintf('Bus "%s" should NOT have CausationIdMiddleware when disabled', $busId));
        }
    }

    public function test_does_not_inject_when_bus_has_no_iterator_argument(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.default_bus', 'messenger.default_bus');
        $container->register('somework_cqrs.messenger.middleware.causation_id', \stdClass::class);

        // Register a bus with a non-IteratorArgument first argument
        $definition = new Definition(\stdClass::class);
        $definition->setArgument(0, 'not-an-iterator');
        $container->setDefinition('messenger.default_bus', $definition);

        $pass = new CausationIdMiddlewarePass();
        $pass->process($container);

        // Should not crash, and the argument should remain unchanged
        self::assertSame('not-an-iterator', $container->getDefinition('messenger.default_bus')->getArgument(0));
    }

    /**
     * @param list<string> $busIds
     */
    private function createContainerWithBuses(array $busIds): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.default_bus', $busIds[0]);
        $container->register('somework_cqrs.messenger.middleware.causation_id', \stdClass::class);

        foreach ($busIds as $busId) {
            $definition = new Definition(\stdClass::class);
            $definition->setArgument(0, new IteratorArgument([
                new Reference('messenger.middleware.some_existing'),
            ]));
            $container->setDefinition($busId, $definition);
        }

        return $container;
    }
}
