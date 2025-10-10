<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use SomeWork\CqrsBundle\Support\RetryPolicyStampDecider;
use SomeWork\CqrsBundle\Support\TransportMappingProvider;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\OrderPlacedEvent;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class CqrsExtensionRegistrarsTest extends TestCase
{
    public function test_helper_registrations_produce_expected_container_configuration(): void
    {
        $extension = new CqrsExtension();
        $container = new ContainerBuilder();

        $this->registerMessengerBuses($container);
        $this->registerServiceFixtures($container);

        $config = [
            'default_bus' => 'messenger.default_bus',
            'buses' => [
                'command' => 'messenger.default_bus',
                'query' => 'messenger.default_bus',
                'event' => 'messenger.default_bus',
                'command_async' => 'messenger.bus.command_async',
                'event_async' => 'messenger.bus.event_async',
            ],
            'naming' => [
                'default' => 'app.naming.default',
                'command' => 'app.naming.command',
                'query' => 'app.naming.query',
                'event' => 'app.naming.event',
            ],
            'retry_policies' => [
                'command' => [
                    'default' => 'app.retry.default',
                    'map' => [
                        CreateTaskCommand::class => 'app.retry.command_custom',
                    ],
                ],
                'query' => [
                    'default' => 'app.retry.query_default',
                    'map' => [],
                ],
                'event' => [
                    'default' => 'app.retry.event_default',
                    'map' => [
                        OrderPlacedEvent::class => 'app.retry.event_custom',
                    ],
                ],
            ],
            'serialization' => [
                'default' => 'app.serializer.default',
                'command' => [
                    'default' => null,
                    'map' => [
                        CreateTaskCommand::class => 'app.serializer.command_custom',
                    ],
                ],
                'query' => [
                    'default' => 'app.serializer.query_default',
                    'map' => [],
                ],
                'event' => [
                    'default' => null,
                    'map' => [
                        OrderPlacedEvent::class => 'app.serializer.event_custom',
                    ],
                ],
            ],
            'metadata' => [
                'default' => 'app.metadata.default',
                'command' => [
                    'default' => 'app.metadata.command_default',
                    'map' => [],
                ],
                'query' => [
                    'default' => null,
                    'map' => [
                        FindTaskQuery::class => 'app.metadata.query_custom',
                    ],
                ],
                'event' => [
                    'default' => null,
                    'map' => [],
                ],
            ],
            'transports' => [
                'command' => [
                    'default' => ['command-default'],
                    'map' => [
                        CreateTaskCommand::class => ['command-map'],
                    ],
                    'stamp' => 'transport_names',
                ],
                'command_async' => [
                    'default' => ['command-async-default'],
                    'map' => [],
                    'stamp' => 'transport_names',
                ],
                'query' => [
                    'default' => [],
                    'map' => [
                        FindTaskQuery::class => ['query-map'],
                    ],
                    'stamp' => 'transport_names',
                ],
                'event' => [
                    'default' => ['event-default'],
                    'map' => [],
                    'stamp' => 'transport_names',
                ],
                'event_async' => [
                    'default' => ['event-async-default'],
                    'map' => [
                        OrderPlacedEvent::class => ['event-async-map'],
                    ],
                    'stamp' => 'transport_names',
                ],
            ],
            'dispatch_modes' => [
                'command' => [
                    'default' => 'sync',
                    'map' => [
                        CreateTaskCommand::class => 'async',
                    ],
                ],
                'event' => [
                    'default' => 'async',
                    'map' => [
                        OrderPlacedEvent::class => 'sync',
                    ],
                ],
            ],
            'async' => [
                'dispatch_after_current_bus' => [
                    'command' => [
                        'default' => false,
                        'map' => [
                            CreateTaskCommand::class => true,
                        ],
                    ],
                    'event' => [
                        'default' => true,
                        'map' => [
                            OrderPlacedEvent::class => false,
                        ],
                    ],
                ],
            ],
        ];

        $extension->load([$config], $container);

        self::assertSame('app.serializer.default', (string) $container->getAlias('somework_cqrs.serializer.command'));
        self::assertSame('app.retry.default', (string) $container->getAlias('somework_cqrs.retry.command'));
        self::assertSame('app.metadata.default', (string) $container->getAlias('somework_cqrs.metadata.event'));

        $expectedTransportMapping = [
            'command' => [
                'default' => ['command-default'],
                'map' => [CreateTaskCommand::class => ['command-map']],
            ],
            'command_async' => [
                'default' => ['command-async-default'],
                'map' => [],
            ],
            'query' => [
                'default' => [],
                'map' => [FindTaskQuery::class => ['query-map']],
            ],
            'event' => [
                'default' => ['event-default'],
                'map' => [],
            ],
            'event_async' => [
                'default' => ['event-async-default'],
                'map' => [OrderPlacedEvent::class => ['event-async-map']],
            ],
        ];

        self::assertSame($expectedTransportMapping, $container->getParameter('somework_cqrs.transport_mapping'));
        self::assertSame(
            [
                'command-default',
                'command-map',
                'command-async-default',
                'query-map',
                'event-default',
                'event-async-default',
                'event-async-map',
            ],
            $container->getParameter('somework_cqrs.transport_names'),
        );
        self::assertSame(
            [
                'command' => 'transport_names',
                'command_async' => 'transport_names',
                'query' => 'transport_names',
                'event' => 'transport_names',
                'event_async' => 'transport_names',
            ],
            $container->getParameter('somework_cqrs.transport_stamp_types'),
        );

        $dispatchModeDefinition = $container->getDefinition('somework_cqrs.dispatch_mode_decider');
        self::assertSame('sync', $dispatchModeDefinition->getArgument('$commandDefault')->value);
        self::assertSame('async', $dispatchModeDefinition->getArgument('$eventDefault')->value);

        $dispatchAfterDefinition = $container->getDefinition('somework_cqrs.dispatch_after_current_bus_decider');
        self::assertFalse($dispatchAfterDefinition->getArgument('$commandDefault'));
        self::assertTrue($dispatchAfterDefinition->getArgument('$eventDefault'));
        $commandToggleReference = $dispatchAfterDefinition->getArgument('$commandToggles');
        self::assertInstanceOf(Reference::class, $commandToggleReference);
        self::assertStringContainsString('service_locator', (string) $commandToggleReference);

        $commandRetryDefinition = $container->getDefinition('somework_cqrs.stamp_decider.command_retry');
        self::assertSame(RetryPolicyStampDecider::class, $commandRetryDefinition->getClass());
        $commandRetryReference = $commandRetryDefinition->getArgument('$retryPolicies');
        self::assertInstanceOf(Reference::class, $commandRetryReference);
        self::assertSame('somework_cqrs.retry.command_resolver', (string) $commandRetryReference);

        $transportDeciderDefinition = $container->getDefinition('somework_cqrs.stamp_decider.message_transport');
        self::assertSame(MessageTransportStampDecider::class, $transportDeciderDefinition->getClass());

        $transportProviderDefinition = $container->getDefinition('somework_cqrs.transport_mapping_provider');
        self::assertSame(TransportMappingProvider::class, $transportProviderDefinition->getClass());
        self::assertSame($expectedTransportMapping, $transportProviderDefinition->getArgument('$mapping'));

        $commandBusDefinition = $container->getDefinition(CommandBus::class);
        $asyncReference = $commandBusDefinition->getArgument('$asyncBus');
        self::assertInstanceOf(Reference::class, $asyncReference);
        self::assertSame('messenger.bus.command_async', (string) $asyncReference);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $asyncReference->getInvalidBehavior());
        $stampsDeciderReference = $commandBusDefinition->getArgument('$stampsDecider');
        self::assertInstanceOf(Reference::class, $stampsDeciderReference);
        self::assertSame('somework_cqrs.stamps_decider', (string) $stampsDeciderReference);

        $container->compile();
    }

    private function registerMessengerBuses(ContainerBuilder $container): void
    {
        $container->register('messenger.default_bus', \stdClass::class)->setPublic(true);
        $container->register('messenger.bus.command_async', \stdClass::class)->setPublic(true);
        $container->register('messenger.bus.event_async', \stdClass::class)->setPublic(true);

        $container->register('messenger.default_bus.messenger.handlers_locator', ServiceLocator::class)->setArguments([[]])->setPublic(true);
        $container->register('messenger.bus.command_async.messenger.handlers_locator', ServiceLocator::class)->setArguments([[]])->setPublic(true);
        $container->register('messenger.bus.event_async.messenger.handlers_locator', ServiceLocator::class)->setArguments([[]])->setPublic(true);
    }

    private function registerServiceFixtures(ContainerBuilder $container): void
    {
        $serviceIds = [
            'app.naming.default',
            'app.naming.command',
            'app.naming.query',
            'app.naming.event',
            'app.retry.default',
            'app.retry.command_custom',
            'app.retry.query_default',
            'app.retry.event_default',
            'app.retry.event_custom',
            'app.serializer.default',
            'app.serializer.command_custom',
            'app.serializer.query_default',
            'app.serializer.event_custom',
            'app.metadata.default',
            'app.metadata.command_default',
            'app.metadata.query_custom',
        ];

        foreach ($serviceIds as $serviceId) {
            $container->register($serviceId, \stdClass::class)->setPublic(true);
        }
    }
}
