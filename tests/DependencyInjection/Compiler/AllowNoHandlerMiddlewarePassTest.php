<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\AllowNoHandlerMiddlewarePass;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class AllowNoHandlerMiddlewarePassTest extends TestCase
{
    public function test_it_prepends_middleware_to_configured_bus(): void
    {
        $container = new ContainerBuilder();

        $container->setDefinition('somework_cqrs.messenger.middleware.allow_no_handler', new Definition());
        $container->setParameter('somework_cqrs.allow_no_handler.bus_ids', ['messenger.bus.events']);

        $busDefinition = new Definition();
        $busDefinition->setArgument(0, new IteratorArgument([new Reference('existing.middleware')]));

        $container->setDefinition('messenger.bus.events', $busDefinition);

        (new AllowNoHandlerMiddlewarePass())->process($container);

        /** @var IteratorArgument $argument */
        $argument = $container->findDefinition('messenger.bus.events')->getArgument(0);

        $middleware = $argument->getValues();

        self::assertCount(2, $middleware);
        self::assertSame(
            [
                'somework_cqrs.messenger.middleware.allow_no_handler',
                'existing.middleware',
            ],
            array_map(static fn (Reference $reference): string => (string) $reference, $middleware),
        );
    }

    public function test_it_resolves_traceable_bus_inner_definition(): void
    {
        $container = new ContainerBuilder();

        $container->setDefinition('somework_cqrs.messenger.middleware.allow_no_handler', new Definition());
        $container->setParameter('somework_cqrs.allow_no_handler.bus_ids', ['messenger.bus.events']);

        $innerDefinition = new Definition();
        $innerDefinition->setArgument(0, new IteratorArgument([new Reference('existing.middleware')]));
        $container->setDefinition('debug.traced.messenger.bus.events.inner', $innerDefinition);

        $traceableDefinition = new Definition();
        $traceableDefinition->setArgument(0, new Reference('debug.traced.messenger.bus.events.inner'));
        $container->setDefinition('debug.traced.messenger.bus.events', $traceableDefinition);

        $container->setAlias('messenger.bus.events', 'debug.traced.messenger.bus.events');

        (new AllowNoHandlerMiddlewarePass())->process($container);

        /** @var IteratorArgument $argument */
        $argument = $container->findDefinition('debug.traced.messenger.bus.events.inner')->getArgument(0);

        $middleware = $argument->getValues();

        self::assertCount(2, $middleware);
        self::assertSame(
            [
                'somework_cqrs.messenger.middleware.allow_no_handler',
                'existing.middleware',
            ],
            array_map(static fn (Reference $reference): string => (string) $reference, $middleware),
        );
    }
}
