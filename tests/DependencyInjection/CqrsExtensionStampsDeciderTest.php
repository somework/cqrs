<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\DependencyInjection\Registration\StampsDeciderRegistrar;
use SomeWork\CqrsBundle\Support\CausationIdStampDecider;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusStampDecider;
use SomeWork\CqrsBundle\Support\IdempotencyStampDecider;
use SomeWork\CqrsBundle\Support\MessageMetadataStampDecider;
use SomeWork\CqrsBundle\Support\MessageSerializerStampDecider;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use SomeWork\CqrsBundle\Support\RetryPolicyStampDecider;
use SomeWork\CqrsBundle\Support\SequenceStampDecider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(CqrsExtension::class)]
#[CoversClass(StampsDeciderRegistrar::class)]
final class CqrsExtensionStampsDeciderTest extends TestCase
{
    public function test_decider_services_are_registered_with_expected_priorities(): void
    {
        $extension = new CqrsExtension();
        $container = new ContainerBuilder();

        $container->register('messenger.default_bus', \stdClass::class)->setPublic(true);
        $container->register('messenger.default_bus.messenger.handlers_locator', ServiceLocator::class)
            ->setArguments([[]])
            ->setPublic(true);

        $extension->load([], $container);

        $expectedClasses = [
            'somework_cqrs.stamp_decider.command_retry' => RetryPolicyStampDecider::class,
            'somework_cqrs.stamp_decider.command_serializer' => MessageSerializerStampDecider::class,
            'somework_cqrs.stamp_decider.command_metadata' => MessageMetadataStampDecider::class,
            'somework_cqrs.stamp_decider.query_retry' => RetryPolicyStampDecider::class,
            'somework_cqrs.stamp_decider.query_serializer' => MessageSerializerStampDecider::class,
            'somework_cqrs.stamp_decider.query_metadata' => MessageMetadataStampDecider::class,
            'somework_cqrs.stamp_decider.event_retry' => RetryPolicyStampDecider::class,
            'somework_cqrs.stamp_decider.event_serializer' => MessageSerializerStampDecider::class,
            'somework_cqrs.stamp_decider.event_metadata' => MessageMetadataStampDecider::class,
            'somework_cqrs.stamp_decider.message_transport' => MessageTransportStampDecider::class,
            'somework_cqrs.stamp_decider.event_sequence' => SequenceStampDecider::class,
            'somework_cqrs.dispatch_after_current_bus_stamp_decider' => DispatchAfterCurrentBusStampDecider::class,
        ];

        foreach ($expectedClasses as $serviceId => $class) {
            self::assertTrue($container->hasDefinition($serviceId), $serviceId.' should be defined');
            self::assertSame($class, $container->getDefinition($serviceId)->getClass());
        }

        $expectedPriorities = [
            'somework_cqrs.stamp_decider.command_retry' => 200,
            'somework_cqrs.stamp_decider.command_serializer' => 150,
            'somework_cqrs.stamp_decider.command_metadata' => 125,
            'somework_cqrs.stamp_decider.query_retry' => 200,
            'somework_cqrs.stamp_decider.query_serializer' => 150,
            'somework_cqrs.stamp_decider.query_metadata' => 125,
            'somework_cqrs.stamp_decider.event_retry' => 200,
            'somework_cqrs.stamp_decider.event_serializer' => 150,
            'somework_cqrs.stamp_decider.event_metadata' => 125,
            'somework_cqrs.stamp_decider.message_transport' => 175,
            'somework_cqrs.stamp_decider.event_sequence' => 110,
            'somework_cqrs.dispatch_after_current_bus_stamp_decider' => 0,
        ];

        foreach ($expectedPriorities as $serviceId => $priority) {
            $tags = $container->getDefinition($serviceId)->getTag('somework_cqrs.dispatch_stamp_decider');
            self::assertCount(1, $tags, $serviceId.' should have exactly one dispatch stamp decider tag');
            self::assertSame($priority, $tags[0]['priority']);
        }

        $expectedMessageTypes = [
            'somework_cqrs.stamp_decider.command_retry' => [Command::class],
            'somework_cqrs.stamp_decider.command_serializer' => [Command::class],
            'somework_cqrs.stamp_decider.command_metadata' => [Command::class],
            'somework_cqrs.stamp_decider.query_retry' => [Query::class],
            'somework_cqrs.stamp_decider.query_serializer' => [Query::class],
            'somework_cqrs.stamp_decider.query_metadata' => [Query::class],
            'somework_cqrs.stamp_decider.event_retry' => [Event::class],
            'somework_cqrs.stamp_decider.event_serializer' => [Event::class],
            'somework_cqrs.stamp_decider.event_metadata' => [Event::class],
            'somework_cqrs.stamp_decider.message_transport' => [Command::class, Query::class, Event::class],
            'somework_cqrs.stamp_decider.event_sequence' => [Event::class],
        ];

        foreach ($expectedMessageTypes as $serviceId => $types) {
            $tags = $container->getDefinition($serviceId)->getTag('somework_cqrs.dispatch_stamp_decider');
            self::assertSame($types, $tags[0]['message_types'] ?? null, $serviceId.' should declare message types');
        }
    }

    public function test_extension_sets_idempotency_enabled_parameter(): void
    {
        $container = $this->createContainer();

        self::assertTrue($container->hasParameter('somework_cqrs.idempotency.enabled'));
        self::assertTrue($container->getParameter('somework_cqrs.idempotency.enabled'));
    }

    public function test_extension_sets_idempotency_ttl_parameter(): void
    {
        $container = $this->createContainer();

        self::assertTrue($container->hasParameter('somework_cqrs.idempotency.ttl'));
        self::assertSame(300, $container->getParameter('somework_cqrs.idempotency.ttl'));
    }

    public function test_idempotency_decider_registered_when_enabled(): void
    {
        $container = $this->createContainer();

        self::assertTrue(
            $container->hasDefinition('somework_cqrs.stamp_decider.idempotency'),
            'IdempotencyStampDecider should be registered when enabled=true',
        );

        $definition = $container->getDefinition('somework_cqrs.stamp_decider.idempotency');
        self::assertSame(IdempotencyStampDecider::class, $definition->getClass());
    }

    public function test_idempotency_decider_not_registered_when_disabled(): void
    {
        $container = $this->createContainer([
            'idempotency' => ['enabled' => false],
        ]);

        self::assertFalse(
            $container->hasDefinition('somework_cqrs.stamp_decider.idempotency'),
            'IdempotencyStampDecider should NOT be registered when enabled=false',
        );
    }

    public function test_idempotency_decider_has_priority_50(): void
    {
        $container = $this->createContainer();

        $tags = $container->getDefinition('somework_cqrs.stamp_decider.idempotency')
            ->getTag('somework_cqrs.dispatch_stamp_decider');

        self::assertCount(1, $tags);
        self::assertSame(50, $tags[0]['priority']);
    }

    public function test_idempotency_decider_has_no_message_types(): void
    {
        $container = $this->createContainer();

        $tags = $container->getDefinition('somework_cqrs.stamp_decider.idempotency')
            ->getTag('somework_cqrs.dispatch_stamp_decider');

        self::assertArrayNotHasKey('message_types', $tags[0]);
    }

    public function test_idempotency_decider_receives_ttl_from_config(): void
    {
        $container = $this->createContainer([
            'idempotency' => ['ttl' => 600],
        ]);

        $definition = $container->getDefinition('somework_cqrs.stamp_decider.idempotency');
        self::assertSame(600.0, $definition->getArgument('$defaultTtl'));
    }

    public function test_idempotency_decider_receives_logger(): void
    {
        $container = $this->createContainer();

        $definition = $container->getDefinition('somework_cqrs.stamp_decider.idempotency');

        /** @var Reference $loggerRef */
        $loggerRef = $definition->getArgument('$logger');
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(Reference::class, $loggerRef);
        self::assertSame('logger', (string) $loggerRef);
    }

    public function test_causation_id_stamp_decider_registered_by_default(): void
    {
        $container = $this->createContainer();

        self::assertTrue(
            $container->hasDefinition('somework_cqrs.stamp_decider.causation_id'),
            'CausationIdStampDecider should be registered by default',
        );

        $definition = $container->getDefinition('somework_cqrs.stamp_decider.causation_id');
        self::assertSame(CausationIdStampDecider::class, $definition->getClass());
    }

    public function test_causation_id_stamp_decider_not_registered_when_disabled(): void
    {
        $container = $this->createContainer([
            'causation_id' => ['enabled' => false],
        ]);

        self::assertFalse(
            $container->hasDefinition('somework_cqrs.stamp_decider.causation_id'),
            'CausationIdStampDecider should NOT be registered when causation_id.enabled=false',
        );
    }

    public function test_causation_id_parameters_set(): void
    {
        $container = $this->createContainer([
            'causation_id' => [
                'enabled' => true,
                'buses' => ['messenger.bus.commands'],
            ],
        ]);

        self::assertTrue($container->hasParameter('somework_cqrs.causation_id.enabled'));
        self::assertTrue($container->getParameter('somework_cqrs.causation_id.enabled'));

        self::assertTrue($container->hasParameter('somework_cqrs.causation_id.buses'));
        self::assertSame(['messenger.bus.commands'], $container->getParameter('somework_cqrs.causation_id.buses'));
    }

    public function test_causation_id_stamp_decider_has_priority_100(): void
    {
        $container = $this->createContainer();

        $tags = $container->getDefinition('somework_cqrs.stamp_decider.causation_id')
            ->getTag('somework_cqrs.dispatch_stamp_decider');

        self::assertCount(1, $tags);
        self::assertSame(100, $tags[0]['priority']);
    }

    public function test_causation_id_stamp_decider_has_no_message_types(): void
    {
        $container = $this->createContainer();

        $tags = $container->getDefinition('somework_cqrs.stamp_decider.causation_id')
            ->getTag('somework_cqrs.dispatch_stamp_decider');

        self::assertArrayNotHasKey('message_types', $tags[0]);
    }

    public function test_causation_id_stamp_decider_receives_logger(): void
    {
        $container = $this->createContainer();

        $definition = $container->getDefinition('somework_cqrs.stamp_decider.causation_id');

        /** @var Reference $loggerRef */
        $loggerRef = $definition->getArgument('$logger');
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(Reference::class, $loggerRef);
        self::assertSame('logger', (string) $loggerRef);
    }

    public function test_causation_id_disabled_parameters_still_set(): void
    {
        $container = $this->createContainer([
            'causation_id' => ['enabled' => false],
        ]);

        self::assertTrue($container->hasParameter('somework_cqrs.causation_id.enabled'));
        self::assertFalse($container->getParameter('somework_cqrs.causation_id.enabled'));

        self::assertTrue($container->hasParameter('somework_cqrs.causation_id.buses'));
        self::assertSame([], $container->getParameter('somework_cqrs.causation_id.buses'));
    }

    public function test_idempotency_decider_uses_default_ttl_when_not_configured(): void
    {
        $container = $this->createContainer();

        $definition = $container->getDefinition('somework_cqrs.stamp_decider.idempotency');
        self::assertSame(300.0, $definition->getArgument('$defaultTtl'));
    }

    public function test_both_idempotency_and_causation_id_disabled(): void
    {
        $container = $this->createContainer([
            'idempotency' => ['enabled' => false],
            'causation_id' => ['enabled' => false],
        ]);

        self::assertFalse(
            $container->hasDefinition('somework_cqrs.stamp_decider.idempotency'),
            'IdempotencyStampDecider should NOT be registered when disabled',
        );
        self::assertFalse(
            $container->hasDefinition('somework_cqrs.stamp_decider.causation_id'),
            'CausationIdStampDecider should NOT be registered when disabled',
        );

        // The core deciders and dispatch-after-current-bus should still exist
        self::assertTrue($container->hasDefinition('somework_cqrs.stamp_decider.command_retry'));
        self::assertTrue($container->hasDefinition('somework_cqrs.dispatch_after_current_bus_stamp_decider'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createContainer(array $config = []): ContainerBuilder
    {
        $extension = new CqrsExtension();
        $container = new ContainerBuilder();

        $container->register('messenger.default_bus', \stdClass::class)->setPublic(true);
        $container->register('messenger.default_bus.messenger.handlers_locator', ServiceLocator::class)
            ->setArguments([[]])
            ->setPublic(true);

        $extension->load([] === $config ? [] : [$config], $container);

        return $container;
    }
}
