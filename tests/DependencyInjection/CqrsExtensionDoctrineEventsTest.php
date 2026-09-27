<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\EventBusInterface;
use SomeWork\CqrsBundle\DependencyInjection\Configuration;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\DependencyInjection\Registration\DoctrineEventsRegistrar;
use SomeWork\CqrsBundle\DependencyInjection\Registration\OutboxRegistrar;
use SomeWork\CqrsBundle\Doctrine\RecordedEventsListener;
use SomeWork\CqrsBundle\Outbox\RecordedEventsPublisher;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;

use const PHP_INT_MAX;

#[CoversClass(CqrsExtension::class)]
#[CoversClass(Configuration::class)]
#[CoversClass(DoctrineEventsRegistrar::class)]
#[CoversClass(OutboxRegistrar::class)]
final class CqrsExtensionDoctrineEventsTest extends TestCase
{
    public function test_disabled_by_default(): void
    {
        $container = $this->load(['outbox' => ['enabled' => true]]);

        self::assertFalse($container->hasDefinition(DoctrineEventsRegistrar::LISTENER_ID));
    }

    public function test_the_listener_stores_the_events_of_every_entity_manager_after_the_other_listeners(): void
    {
        $container = $this->load(['outbox' => ['enabled' => true, 'connection' => 'business'], 'doctrine_events' => ['enabled' => true]]);

        $listener = $container->getDefinition(DoctrineEventsRegistrar::LISTENER_ID);
        self::assertSame(RecordedEventsListener::class, $listener->getClass());
        self::assertFalse($listener->isPublic());
        self::assertSame([
            ['event' => 'preFlush', 'priority' => -1024],
            ['event' => 'onFlush', 'priority' => -1024],
            ['event' => 'postFlush', 'priority' => PHP_INT_MAX],
            ['event' => 'onClear'],
        ], $listener->getTag('doctrine.event_listener'), 'No "connection" attribute: every connection. The store runs before the other postFlush listeners.');
        self::assertSame([['method' => 'reset']], $listener->getTag('kernel.reset'));

        $connection = $listener->getArgument('$outboxConnection');
        self::assertInstanceOf(Reference::class, $connection);
        self::assertSame('doctrine.dbal.business_connection', (string) $connection);

        $publisher = $listener->getArgument('$publisher');
        self::assertInstanceOf(ServiceClosureArgument::class, $publisher, 'Lazy: the event bus depends on the entity manager.');
        $values = $publisher->getValues();
        self::assertCount(1, $values);
        self::assertInstanceOf(Reference::class, $values[0]);
        self::assertSame(OutboxRegistrar::PUBLISHER_ID, (string) $values[0]);
    }

    public function test_the_publisher_stores_through_the_event_bus_interface_on_the_outbox_connection(): void
    {
        $container = $this->load(['outbox' => ['enabled' => true, 'connection' => 'business']]);

        $publisher = $container->getDefinition(OutboxRegistrar::PUBLISHER_ID);
        self::assertSame(RecordedEventsPublisher::class, $publisher->getClass());
        $eventBus = $publisher->getArgument('$eventBus');
        self::assertInstanceOf(Reference::class, $eventBus);
        self::assertSame(EventBusInterface::class, (string) $eventBus, 'The alias, which a test double may replace.');
        $connection = $publisher->getArgument('$connection');
        self::assertInstanceOf(Reference::class, $connection);
        self::assertSame('doctrine.dbal.business_connection', (string) $connection);
        self::assertSame(OutboxRegistrar::PUBLISHER_ID, (string) $container->getAlias(RecordedEventsPublisher::class));
    }

    public function test_there_is_no_publisher_with_a_custom_storage(): void
    {
        $container = $this->load(['outbox' => ['enabled' => true, 'storage' => InMemoryOutboxStorage::class]]);

        self::assertFalse($container->hasDefinition(OutboxRegistrar::PUBLISHER_ID));
        self::assertFalse($container->hasAlias(RecordedEventsPublisher::class));
    }

    public function test_requires_the_outbox(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"somework_cqrs.doctrine_events" stores the events recorded by entities in the transactional outbox, but the outbox is disabled.');

        $this->load(['doctrine_events' => ['enabled' => true]]);
    }

    public function test_rejects_a_custom_outbox_storage(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('names another storage ("'.InMemoryOutboxStorage::class.'"). Remove "outbox.storage" (decorate the service "somework_cqrs.outbox.storage" instead)');

        $this->load(['outbox' => ['enabled' => true, 'storage' => InMemoryOutboxStorage::class], 'doctrine_events' => ['enabled' => true]]);
    }

    public function test_requires_doctrine_orm(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('but doctrine/orm is not installed');

        $this->load(['outbox' => ['enabled' => true], 'doctrine_events' => ['enabled' => true]], static fn (string $class): bool => EntityManager::class !== $class && class_exists($class));
    }

    /**
     * @param array<string, mixed>          $config
     * @param (\Closure(string): bool)|null $classExists
     */
    private function load(array $config, ?\Closure $classExists = null): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new CqrsExtension($classExists))->load([$config], $container);

        return $container;
    }
}
