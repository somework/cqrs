<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusStampDecider;
use SomeWork\CqrsBundle\Support\MessageMetadataStampDecider;
use SomeWork\CqrsBundle\Support\MessageSerializerStampDecider;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use SomeWork\CqrsBundle\Support\MessageTransportStampFactory;
use SomeWork\CqrsBundle\Support\RetryPolicyStampDecider;
use SomeWork\CqrsBundle\Support\StampsDecider;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

final class StampsDeciderRegistrar
{
    public function __construct(private readonly ContainerHelper $helper)
    {
    }

    /**
     * @param array{
     *     command?: string|null,
     *     command_async?: string|null,
     *     query?: string|null,
     *     event?: string|null,
     *     event_async?: string|null,
     * } $buses
     */
    public function register(ContainerBuilder $container, array $buses): void
    {
        $container->setDefinition('somework_cqrs.transport_stamp_factory', (new Definition(MessageTransportStampFactory::class))
            ->setPublic(false));
        $container->setAlias(MessageTransportStampFactory::class, 'somework_cqrs.transport_stamp_factory')->setPublic(false);

        $deciderConfigurations = [
            [
                'service_id_suffix' => 'command_retry',
                'class' => RetryPolicyStampDecider::class,
                'arguments' => [
                    '$retryPolicies' => $this->helper->createResolverReference('retry', 'command'),
                    '$messageType' => Command::class,
                ],
                'priority' => 200,
            ],
            [
                'service_id_suffix' => 'command_serializer',
                'class' => MessageSerializerStampDecider::class,
                'arguments' => [
                    '$serializers' => $this->helper->createResolverReference('serializer', 'command'),
                    '$messageType' => Command::class,
                ],
                'priority' => 150,
            ],
            [
                'service_id_suffix' => 'query_retry',
                'class' => RetryPolicyStampDecider::class,
                'arguments' => [
                    '$retryPolicies' => $this->helper->createResolverReference('retry', 'query'),
                    '$messageType' => Query::class,
                ],
                'priority' => 200,
            ],
            [
                'service_id_suffix' => 'query_serializer',
                'class' => MessageSerializerStampDecider::class,
                'arguments' => [
                    '$serializers' => $this->helper->createResolverReference('serializer', 'query'),
                    '$messageType' => Query::class,
                ],
                'priority' => 150,
            ],
            [
                'service_id_suffix' => 'query_metadata',
                'class' => MessageMetadataStampDecider::class,
                'arguments' => [
                    '$providers' => $this->helper->createResolverReference('metadata', 'query'),
                    '$messageType' => Query::class,
                ],
                'priority' => 125,
            ],
            [
                'service_id_suffix' => 'command_metadata',
                'class' => MessageMetadataStampDecider::class,
                'arguments' => [
                    '$providers' => $this->helper->createResolverReference('metadata', 'command'),
                    '$messageType' => Command::class,
                ],
                'priority' => 125,
            ],
            [
                'service_id_suffix' => 'event_retry',
                'class' => RetryPolicyStampDecider::class,
                'arguments' => [
                    '$retryPolicies' => $this->helper->createResolverReference('retry', 'event'),
                    '$messageType' => Event::class,
                ],
                'priority' => 200,
            ],
            [
                'service_id_suffix' => 'event_serializer',
                'class' => MessageSerializerStampDecider::class,
                'arguments' => [
                    '$serializers' => $this->helper->createResolverReference('serializer', 'event'),
                    '$messageType' => Event::class,
                ],
                'priority' => 150,
            ],
            [
                'service_id_suffix' => 'event_metadata',
                'class' => MessageMetadataStampDecider::class,
                'arguments' => [
                    '$providers' => $this->helper->createResolverReference('metadata', 'event'),
                    '$messageType' => Event::class,
                ],
                'priority' => 125,
            ],
            [
                'service_id_suffix' => 'message_transport',
                'class' => MessageTransportStampDecider::class,
                'arguments' => [
                    '$stampFactory' => new Reference('somework_cqrs.transport_stamp_factory'),
                    '$stampTypes' => '%somework_cqrs.transport_stamp_types%',
                    '$commandTransports' => $this->helper->createResolverReference('transports', 'command'),
                    '$commandAsyncTransports' => $this->helper->createOptionalTransportResolverReference('command_async', $buses),
                    '$queryTransports' => $this->helper->createResolverReference('transports', 'query'),
                    '$eventTransports' => $this->helper->createResolverReference('transports', 'event'),
                    '$eventAsyncTransports' => $this->helper->createOptionalTransportResolverReference('event_async', $buses),
                ],
                'priority' => 175,
            ],
            [
                'service_id' => 'somework_cqrs.dispatch_after_current_bus_stamp_decider',
                'class' => DispatchAfterCurrentBusStampDecider::class,
                'arguments' => [
                    '$decider' => new Reference('somework_cqrs.dispatch_after_current_bus_decider'),
                ],
                'priority' => 0,
            ],
        ];

        foreach ($deciderConfigurations as $configuration) {
            $definition = new Definition($configuration['class']);

            foreach ($configuration['arguments'] as $name => $value) {
                $definition->setArgument($name, $value);
            }

            $definition->addTag('somework_cqrs.dispatch_stamp_decider', ['priority' => $configuration['priority']]);
            $definition->setPublic(false);

            $serviceId = $configuration['service_id'] ?? sprintf('somework_cqrs.stamp_decider.%s', $configuration['service_id_suffix']);
            $container->setDefinition($serviceId, $definition);
        }

        $definition = new Definition(StampsDecider::class);
        $definition->setArgument('$deciders', new TaggedIteratorArgument('somework_cqrs.dispatch_stamp_decider'));
        $definition->setPublic(false);

        $container->setDefinition('somework_cqrs.stamps_decider', $definition);
    }
}
