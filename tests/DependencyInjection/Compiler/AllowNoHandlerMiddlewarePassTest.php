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
    public function testItPrependsMiddlewareToConfiguredBus(): void
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

        self::assertEquals(
            [
                new Reference('somework_cqrs.messenger.middleware.allow_no_handler'),
                new Reference('existing.middleware'),
            ],
            $argument->getValues()
        );
    }

    public function testItResolvesTraceableBusInnerDefinition(): void
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

        self::assertEquals(
            [
                new Reference('somework_cqrs.messenger.middleware.allow_no_handler'),
                new Reference('existing.middleware'),
            ],
            $argument->getValues()
        );
    }
}
