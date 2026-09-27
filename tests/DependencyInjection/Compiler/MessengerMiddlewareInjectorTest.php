<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\MessengerMiddlewareInjector;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_map;
use function array_values;

#[CoversClass(MessengerMiddlewareInjector::class)]
final class MessengerMiddlewareInjectorTest extends TestCase
{
    public function test_inserts_right_after_dispatch_after_current_bus(): void
    {
        $container = $this->containerWithBus(['messenger.middleware.add_bus_name_stamp_middleware', 'messenger.middleware.dispatch_after_current_bus', 'messenger.bus.default.middleware.send_message', 'messenger.bus.default.middleware.handle_message']);

        self::assertTrue(MessengerMiddlewareInjector::inject($container, 'messenger.bus.default', 'app.first'));
        self::assertTrue(MessengerMiddlewareInjector::inject($container, 'messenger.bus.default', 'app.second'));

        self::assertSame(
            ['messenger.middleware.add_bus_name_stamp_middleware', 'messenger.middleware.dispatch_after_current_bus', 'app.second', 'app.first', 'messenger.bus.default.middleware.send_message', 'messenger.bus.default.middleware.handle_message'],
            $this->middlewareIds($container, 'messenger.bus.default'),
        );
    }

    public function test_goes_after_the_decode_failed_middleware_of_symfony_8_1(): void
    {
        $container = $this->containerWithBus(['messenger.middleware.dispatch_after_current_bus', 'messenger.middleware.decode_failed_message_middleware', 'messenger.middleware.failed_message_processing_middleware', 'messenger.bus.default.middleware.handle_message']);

        MessengerMiddlewareInjector::inject($container, 'messenger.bus.default', 'app.middleware');

        self::assertSame(
            ['messenger.middleware.dispatch_after_current_bus', 'messenger.middleware.decode_failed_message_middleware', 'app.middleware', 'messenger.middleware.failed_message_processing_middleware', 'messenger.bus.default.middleware.handle_message'],
            $this->middlewareIds($container, 'messenger.bus.default'),
        );
    }

    public function test_prepend_puts_middleware_first_once(): void
    {
        $container = $this->containerWithBus(['messenger.middleware.dispatch_after_current_bus', 'messenger.bus.default.middleware.handle_message']);

        self::assertTrue(MessengerMiddlewareInjector::prepend($container, 'messenger.bus.default', 'app.first'));
        self::assertTrue(MessengerMiddlewareInjector::prepend($container, 'messenger.bus.default', 'app.first'));
        self::assertFalse(MessengerMiddlewareInjector::prepend($container, 'unknown.bus', 'app.first'));

        self::assertSame(['app.first', 'messenger.middleware.dispatch_after_current_bus', 'messenger.bus.default.middleware.handle_message'], $this->middlewareIds($container, 'messenger.bus.default'));
    }

    public function test_inserts_first_when_the_anchor_is_missing(): void
    {
        $container = $this->containerWithBus(['messenger.bus.default.middleware.handle_message']);

        MessengerMiddlewareInjector::inject($container, 'messenger.bus.default', 'app.middleware');

        self::assertSame(['app.middleware', 'messenger.bus.default.middleware.handle_message'], $this->middlewareIds($container, 'messenger.bus.default'));
    }

    public function test_skips_when_a_required_anchor_is_missing(): void
    {
        $container = $this->containerWithBus(['messenger.bus.default.middleware.handle_message']);

        self::assertFalse(MessengerMiddlewareInjector::inject($container, 'messenger.bus.default', 'app.middleware', 'deduplicate_middleware', requireAnchor: true));
        self::assertSame(['messenger.bus.default.middleware.handle_message'], $this->middlewareIds($container, 'messenger.bus.default'));
    }

    public function test_is_idempotent(): void
    {
        $container = $this->containerWithBus(['messenger.middleware.dispatch_after_current_bus']);

        MessengerMiddlewareInjector::inject($container, 'messenger.bus.default', 'app.middleware');
        MessengerMiddlewareInjector::inject($container, 'messenger.bus.default', 'app.middleware');

        self::assertSame(['messenger.middleware.dispatch_after_current_bus', 'app.middleware'], $this->middlewareIds($container, 'messenger.bus.default'));
    }

    public function test_follows_aliases_and_traceable_decorators(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('debug.traced.messenger.bus.default.inner', (new Definition())->setArgument(0, new IteratorArgument([new Reference('messenger.middleware.dispatch_after_current_bus')])));
        $container->setDefinition('debug.traced.messenger.bus.default', (new Definition())->setArgument(0, new Reference('debug.traced.messenger.bus.default.inner')));
        $container->setAlias('messenger.default_bus', 'debug.traced.messenger.bus.default');

        self::assertTrue(MessengerMiddlewareInjector::inject($container, 'messenger.default_bus', 'app.middleware'));
        self::assertSame(['messenger.middleware.dispatch_after_current_bus', 'app.middleware'], $this->middlewareIds($container, 'debug.traced.messenger.bus.default.inner'));
    }

    public function test_unknown_or_non_bus_services_are_ignored(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('not.a.bus', new Definition(\stdClass::class));

        self::assertFalse(MessengerMiddlewareInjector::inject($container, 'missing.bus', 'app.middleware'));
        self::assertFalse(MessengerMiddlewareInjector::inject($container, 'not.a.bus', 'app.middleware'));
        self::assertNull(MessengerMiddlewareInjector::findBusDefinition($container, 'not.a.bus'));
    }

    /**
     * @param list<string> $middlewareIds
     */
    private function containerWithBus(array $middlewareIds): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition('messenger.bus.default', (new Definition())->setArgument(0, new IteratorArgument(
            array_map(static fn (string $id): Reference => new Reference($id), $middlewareIds),
        )));

        return $container;
    }

    /**
     * @return list<string>
     */
    private function middlewareIds(ContainerBuilder $container, string $definitionId): array
    {
        $argument = $container->getDefinition($definitionId)->getArgument(0);
        self::assertInstanceOf(IteratorArgument::class, $argument);

        return array_values(array_map(static fn (mixed $reference): string => (string) $reference, $argument->getValues()));
    }
}
