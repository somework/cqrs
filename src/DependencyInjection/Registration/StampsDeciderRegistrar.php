<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Support\CausationIdStampDecider;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusStampDecider;
use SomeWork\CqrsBundle\Support\IdempotencyStampDecider;
use SomeWork\CqrsBundle\Support\MessageMetadataStampDecider;
use SomeWork\CqrsBundle\Support\MessageSerializerStampDecider;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use SomeWork\CqrsBundle\Support\MessageTransportStampFactory;
use SomeWork\CqrsBundle\Support\RateLimitStampDecider;
use SomeWork\CqrsBundle\Support\RetryPolicyStampDecider;
use SomeWork\CqrsBundle\Support\SequenceStampDecider;
use SomeWork\CqrsBundle\Support\StampsDecider;
use SomeWork\CqrsBundle\Support\TransportResolverMap;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\RateLimiter\RateLimiterFactory;

use function sprintf;

/** @internal */
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
     * @param array{enabled: bool, ttl: int}             $idempotencyConfig
     * @param array{enabled: bool, buses?: list<string>} $causationIdConfig
     * @param array{enabled: bool}                       $sequenceConfig
     * @param array{enabled: bool}                       $rateLimitConfig
     */
    public function register(ContainerBuilder $container, array $buses, array $idempotencyConfig = ['enabled' => false, 'ttl' => 300], array $causationIdConfig = ['enabled' => true], array $sequenceConfig = ['enabled' => true], array $rateLimitConfig = ['enabled' => false]): void
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
                'message_types' => [Command::class],
            ],
            [
                'service_id_suffix' => 'command_serializer',
                'class' => MessageSerializerStampDecider::class,
                'arguments' => [
                    '$serializers' => $this->helper->createResolverReference('serializer', 'command'),
                    '$messageType' => Command::class,
                ],
                'priority' => 150,
                'message_types' => [Command::class],
            ],
            [
                'service_id_suffix' => 'query_retry',
                'class' => RetryPolicyStampDecider::class,
                'arguments' => [
                    '$retryPolicies' => $this->helper->createResolverReference('retry', 'query'),
                    '$messageType' => Query::class,
                ],
                'priority' => 200,
                'message_types' => [Query::class],
            ],
            [
                'service_id_suffix' => 'query_serializer',
                'class' => MessageSerializerStampDecider::class,
                'arguments' => [
                    '$serializers' => $this->helper->createResolverReference('serializer', 'query'),
                    '$messageType' => Query::class,
                ],
                'priority' => 150,
                'message_types' => [Query::class],
            ],
            [
                'service_id_suffix' => 'query_metadata',
                'class' => MessageMetadataStampDecider::class,
                'arguments' => [
                    '$providers' => $this->helper->createResolverReference('metadata', 'query'),
                    '$messageType' => Query::class,
                ],
                'priority' => 125,
                'message_types' => [Query::class],
            ],
            [
                'service_id_suffix' => 'command_metadata',
                'class' => MessageMetadataStampDecider::class,
                'arguments' => [
                    '$providers' => $this->helper->createResolverReference('metadata', 'command'),
                    '$messageType' => Command::class,
                ],
                'priority' => 125,
                'message_types' => [Command::class],
            ],
            [
                'service_id_suffix' => 'event_retry',
                'class' => RetryPolicyStampDecider::class,
                'arguments' => [
                    '$retryPolicies' => $this->helper->createResolverReference('retry', 'event'),
                    '$messageType' => Event::class,
                ],
                'priority' => 200,
                'message_types' => [Event::class],
            ],
            [
                'service_id_suffix' => 'event_serializer',
                'class' => MessageSerializerStampDecider::class,
                'arguments' => [
                    '$serializers' => $this->helper->createResolverReference('serializer', 'event'),
                    '$messageType' => Event::class,
                ],
                'priority' => 150,
                'message_types' => [Event::class],
            ],
            [
                'service_id_suffix' => 'event_metadata',
                'class' => MessageMetadataStampDecider::class,
                'arguments' => [
                    '$providers' => $this->helper->createResolverReference('metadata', 'event'),
                    '$messageType' => Event::class,
                ],
                'priority' => 125,
                'message_types' => [Event::class],
            ],
            [
                'service_id_suffix' => 'message_transport',
                'class' => MessageTransportStampDecider::class,
                'arguments' => [
                    '$stampFactory' => new Reference('somework_cqrs.transport_stamp_factory'),
                    '$stampTypes' => '%somework_cqrs.transport_stamp_types%',
                    '$commandResolvers' => $this->createTransportResolverMapDefinition(
                        $this->helper->createResolverReference('transports', 'command'),
                        $this->helper->createOptionalTransportResolverReference('command_async', $buses),
                    ),
                    '$queryResolvers' => $this->createTransportResolverMapDefinition(
                        $this->helper->createResolverReference('transports', 'query'),
                        null,
                    ),
                    '$eventResolvers' => $this->createTransportResolverMapDefinition(
                        $this->helper->createResolverReference('transports', 'event'),
                        $this->helper->createOptionalTransportResolverReference('event_async', $buses),
                    ),
                ],
                'priority' => 175,
                'message_types' => [Command::class, Query::class, Event::class],
            ],
        ];

        if ($causationIdConfig['enabled']) {
            $deciderConfigurations[] = [
                'service_id_suffix' => 'causation_id',
                'class' => CausationIdStampDecider::class,
                'arguments' => [
                    '$causationIdContext' => new Reference('somework_cqrs.causation_id_context'),
                    '$logger' => new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ],
                'priority' => 100,
            ];
        }

        if ($sequenceConfig['enabled']) {
            $deciderConfigurations[] = [
                'service_id_suffix' => 'event_sequence',
                'class' => SequenceStampDecider::class,
                'arguments' => [],
                'priority' => 110,
                'message_types' => [Event::class],
            ];
        }

        if ($rateLimitConfig['enabled'] && class_exists(RateLimiterFactory::class)) {
            foreach (['command' => Command::class, 'query' => Query::class, 'event' => Event::class] as $type => $contract) {
                $deciderConfigurations[] = [
                    'service_id_suffix' => sprintf('%s_rate_limit', $type),
                    'class' => RateLimitStampDecider::class,
                    'arguments' => [
                        '$resolver' => new Reference(sprintf('somework_cqrs.rate_limit.%s_resolver', $type)),
                        '$messageType' => $contract,
                    ],
                    'priority' => 225,
                    'message_types' => [$contract],
                ];
            }
        }

        if ($idempotencyConfig['enabled'] && class_exists(DeduplicateStamp::class)) {
            $deciderConfigurations[] = [
                'service_id_suffix' => 'idempotency',
                'class' => IdempotencyStampDecider::class,
                'arguments' => [
                    '$defaultTtl' => (float) $idempotencyConfig['ttl'],
                    '$logger' => new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ],
                'priority' => 50,
            ];
        }

        $deciderConfigurations[] = [
            'service_id' => 'somework_cqrs.dispatch_after_current_bus_stamp_decider',
            'class' => DispatchAfterCurrentBusStampDecider::class,
            'arguments' => [
                '$decider' => new Reference('somework_cqrs.dispatch_after_current_bus_decider'),
            ],
            'priority' => 0,
        ];

        foreach ($deciderConfigurations as $configuration) {
            $definition = new Definition($configuration['class']);

            foreach ($configuration['arguments'] as $name => $value) {
                $definition->setArgument($name, $value);
            }

            $tagAttributes = ['priority' => $configuration['priority']];

            if (isset($configuration['message_types'])) {
                $tagAttributes['message_types'] = $configuration['message_types'];
            }

            $definition->addTag('somework_cqrs.dispatch_stamp_decider', $tagAttributes);
            $definition->setPublic(false);

            $serviceId = $configuration['service_id']
                ?? sprintf(
                    'somework_cqrs.stamp_decider.%s',
                    $configuration['service_id_suffix'] ?? throw new \LogicException('Decider configuration must define service_id or service_id_suffix'),
                );
            $container->setDefinition($serviceId, $definition);
        }

        $definition = new Definition(StampsDecider::class);
        $definition->setArgument('$deciders', new TaggedIteratorArgument('somework_cqrs.dispatch_stamp_decider'));
        $definition->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        $definition->setPublic(false);

        $container->setDefinition('somework_cqrs.stamps_decider', $definition);
    }

    private function createTransportResolverMapDefinition(
        ?Reference $sync,
        ?Reference $async,
    ): Definition {
        $definition = new Definition(TransportResolverMap::class);
        $definition->setArgument('$sync', $sync);
        $definition->setArgument('$async', $async);

        return $definition;
    }
}
