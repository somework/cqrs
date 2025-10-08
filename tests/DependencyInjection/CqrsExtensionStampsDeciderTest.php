<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusStampDecider;
use SomeWork\CqrsBundle\Support\MessageMetadataStampDecider;
use SomeWork\CqrsBundle\Support\MessageSerializerStampDecider;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use SomeWork\CqrsBundle\Support\RetryPolicyStampDecider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ServiceLocator;

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
            'somework_cqrs.dispatch_after_current_bus_stamp_decider' => 0,
        ];

        foreach ($expectedPriorities as $serviceId => $priority) {
            $tags = $container->getDefinition($serviceId)->getTag('somework_cqrs.dispatch_stamp_decider');
            self::assertCount(1, $tags, $serviceId.' should have exactly one dispatch stamp decider tag');
            self::assertSame($priority, $tags[0]['priority']);
        }
    }
}
