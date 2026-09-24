<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CqrsBusIds;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\EnvelopeAwareHandlersLocatorPass;
use SomeWork\CqrsBundle\Messenger\EnvelopeAwareHandlersLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Handler\HandlersLocator;

#[CoversClass(EnvelopeAwareHandlersLocatorPass::class)]
#[CoversClass(CqrsBusIds::class)]
final class EnvelopeAwareHandlersLocatorPassTest extends TestCase
{
    public function test_decorates_the_locator_of_every_cqrs_bus_after_resolving_aliases(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.default_bus', 'messenger.default_bus');
        $container->setParameter('somework_cqrs.bus.event_async', 'messenger.bus.events_async');
        $container->setAlias('messenger.default_bus', 'messenger.bus.default');
        $container->register('messenger.bus.default.messenger.handlers_locator', HandlersLocator::class);
        $container->register('messenger.bus.events_async.messenger.handlers_locator', HandlersLocator::class);

        (new EnvelopeAwareHandlersLocatorPass())->process($container);

        foreach (['messenger.bus.default', 'messenger.bus.events_async'] as $busId) {
            $decorator = $container->getDefinition('somework_cqrs.envelope_aware_handlers_locator.'.$busId);

            self::assertSame(EnvelopeAwareHandlersLocator::class, $decorator->getClass());
            self::assertSame([$busId.'.messenger.handlers_locator', null, 0], $decorator->getDecoratedService());
        }
    }

    public function test_also_decorates_buses_that_are_not_cqrs_buses(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.default_bus', 'messenger.bus.default');
        // Handler attributes may name any bus, e.g. #[AsCommandHandler(X::class, bus: 'legacy.bus')].
        $container->register('legacy.bus')->addTag('messenger.bus');
        $container->register('legacy.bus.messenger.handlers_locator', HandlersLocator::class);

        (new EnvelopeAwareHandlersLocatorPass())->process($container);

        self::assertTrue($container->hasDefinition('somework_cqrs.envelope_aware_handlers_locator.legacy.bus'));
    }

    public function test_the_default_bus_is_only_a_cqrs_bus_when_a_facade_falls_back_to_it(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.default_bus', 'messenger.bus.default');
        foreach (['command' => 'command.bus', 'query' => 'query.bus', 'event' => 'event.bus'] as $key => $busId) {
            $container->setParameter('somework_cqrs.bus.'.$key, $busId);
        }

        self::assertSame(['command.bus', 'query.bus', 'event.bus'], CqrsBusIds::resolve($container));

        $container->getParameterBag()->remove('somework_cqrs.bus.query');

        self::assertSame(['messenger.bus.default', 'command.bus', 'event.bus'], CqrsBusIds::resolve($container));
    }

    public function test_skips_buses_without_a_handlers_locator(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.default_bus', 'app.custom_bus');

        (new EnvelopeAwareHandlersLocatorPass())->process($container);

        self::assertFalse($container->hasDefinition('somework_cqrs.envelope_aware_handlers_locator.app.custom_bus'));
    }

    public function test_does_nothing_without_bundle_parameters(): void
    {
        $container = new ContainerBuilder();
        $container->register('messenger.bus.default.messenger.handlers_locator', HandlersLocator::class);

        (new EnvelopeAwareHandlersLocatorPass())->process($container);

        self::assertSame([], CqrsBusIds::resolve($container));
        self::assertFalse($container->hasDefinition('somework_cqrs.envelope_aware_handlers_locator.messenger.bus.default'));
    }

    public function test_circular_aliases_are_reported(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias('bus.a', 'bus.b');
        $container->setAlias('bus.b', 'bus.a');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Circular alias');

        CqrsBusIds::resolveAlias($container, 'bus.a');
    }
}
