<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection;

use ArrayObject;
use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Attribute\AsQueryHandler;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\DispatchModeDecider;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Bus\QueryBus;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\CommandHandler;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\EventHandler;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Contract\QueryHandler;
use SomeWork\CqrsBundle\Messenger\EnvelopeAwareHandlersLocator;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusDecider;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusStampDecider;
use SomeWork\CqrsBundle\Support\MessageMetadataProviderResolver;
use SomeWork\CqrsBundle\Support\MessageMetadataStampDecider;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
use SomeWork\CqrsBundle\Support\MessageSerializerStampDecider;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use SomeWork\CqrsBundle\Support\MessageTransportStampFactory;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Support\RetryPolicyStampDecider;
use SomeWork\CqrsBundle\Support\StampDecider;
use SomeWork\CqrsBundle\Support\StampsDecider;
use SomeWork\CqrsBundle\Support\TransportMappingProvider;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function md5;
use function sprintf;

final class CqrsExtension extends Extension
{
    /**
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $defaultBusId = $config['default_bus'] ?? 'messenger.default_bus';
        $container->setParameter('somework_cqrs.default_bus', $defaultBusId);
        $container->setParameter('somework_cqrs.handler_metadata', []);

        $this->guardAsyncBusConfiguration($config);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.php');

        $container->registerForAutoconfiguration(StampDecider::class)
            ->addTag('somework_cqrs.dispatch_stamp_decider');

        $this->registerHandlerAutoconfiguration($container, $config['buses'], $defaultBusId);
        $this->registerNamingStrategies($container, $config['naming']);
        $this->registerRetryPolicies($container, $config['retry_policies']);
        $this->registerSerializers($container, $config['serialization']);
        $this->registerMetadataProviders($container, $config['metadata']);
        $this->registerTransports($container, $config['transports']);
        $this->registerDispatchModeDecider($container, $config['dispatch_modes']);
        $this->registerDispatchAfterCurrentBusDecider($container, $config['async']['dispatch_after_current_bus']);
        $this->registerStampsDecider($container, $config['buses']);
        $this->registerEnvelopeAwareHandlersLocators($container, $config['buses'], $defaultBusId);
        $this->configureBusServices($container, $config['buses'], $defaultBusId);
    }

    public function getAlias(): string
    {
        return 'somework_cqrs';
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
    private function configureBusServices(ContainerBuilder $container, array $buses, string $defaultBusId): void
    {
        if ($container->hasDefinition(CommandBus::class)) {
            $commandBusDefinition = $container->getDefinition(CommandBus::class);
            $commandBusDefinition->setArgument('$syncBus', new Reference($buses['command'] ?? $defaultBusId));

            $commandAsync = $buses['command_async'] ?? null;
            if (null !== $commandAsync) {
                $commandBusDefinition->setArgument('$asyncBus', new Reference($commandAsync, ContainerInterface::NULL_ON_INVALID_REFERENCE));
            } else {
                $commandBusDefinition->setArgument('$asyncBus', null);
            }

            $commandBusDefinition->setArgument('$dispatchModeDecider', new Reference('somework_cqrs.dispatch_mode_decider'));
            $commandBusDefinition->setArgument('$stampsDecider', new Reference('somework_cqrs.stamps_decider'));
        }

        if ($container->hasDefinition(QueryBus::class)) {
            $queryBusDefinition = $container->getDefinition(QueryBus::class);
            $queryBusDefinition->setArgument('$bus', new Reference($buses['query'] ?? $defaultBusId));
            $queryBusDefinition->setArgument('$stampsDecider', new Reference('somework_cqrs.stamps_decider'));
        }

        if ($container->hasDefinition(EventBus::class)) {
            $eventBusDefinition = $container->getDefinition(EventBus::class);
            $eventBusDefinition->setArgument('$syncBus', new Reference($buses['event'] ?? $defaultBusId));

            $eventAsync = $buses['event_async'] ?? null;
            if (null !== $eventAsync) {
                $eventBusDefinition->setArgument('$asyncBus', new Reference($eventAsync, ContainerInterface::NULL_ON_INVALID_REFERENCE));
            } else {
                $eventBusDefinition->setArgument('$asyncBus', null);
            }

            $eventBusDefinition->setArgument('$dispatchModeDecider', new Reference('somework_cqrs.dispatch_mode_decider'));
            $eventBusDefinition->setArgument('$stampsDecider', new Reference('somework_cqrs.stamps_decider'));
        }
    }

    /**
     * @param array{command?: string|null, command_async?: string|null, query?: string|null, event?: string|null, event_async?: string|null} $buses
     */
    private function registerEnvelopeAwareHandlersLocators(ContainerBuilder $container, array $buses, string $defaultBusId): void
    {
        $busIds = array_filter([
            $buses['command'] ?? $defaultBusId,
            $buses['command_async'] ?? null,
            $buses['query'] ?? $defaultBusId,
            $buses['event'] ?? $defaultBusId,
            $buses['event_async'] ?? null,
        ]);

        $busIds = array_values(array_unique($busIds));

        foreach ($busIds as $busId) {
            $resolvedBusId = $this->resolveBusServiceId($container, $busId);
            $locatorId = sprintf('%s.messenger.handlers_locator', $resolvedBusId);

            $decoratorId = sprintf('somework_cqrs.envelope_aware_handlers_locator.%s', md5($locatorId));

            $container->register($decoratorId, EnvelopeAwareHandlersLocator::class)
                ->setDecoratedService($locatorId)
                ->setArgument('$decorated', new Reference($decoratorId.'.inner'));
        }
    }

    private function resolveBusServiceId(ContainerBuilder $container, string $busId): string
    {
        while ($container->hasAlias($busId)) {
            $busId = (string) $container->getAlias($busId);
        }

        return $busId;
    }

    /**
     * @param array<string, string|null> $buses
     */
    private function registerHandlerAutoconfiguration(ContainerBuilder $container, array $buses, string $defaultBusId): void
    {
        $commandBusId = $buses['command'] ?? $defaultBusId;
        $queryBusId = $buses['query'] ?? $defaultBusId;
        $eventBusId = $buses['event'] ?? $defaultBusId;

        $container->registerAttributeForAutoconfiguration(
            AsCommandHandler::class,
            static function (ChildDefinition $definition, AsCommandHandler $attribute) use ($commandBusId): void {
                $bus = $attribute->bus ?? $commandBusId;
                $definition->addTag(
                    'messenger.message_handler',
                    array_filter([
                        'handles' => $attribute->command,
                        'bus' => $bus,
                    ], static fn ($value): bool => null !== $value)
                );
            }
        );

        $container->registerAttributeForAutoconfiguration(
            AsQueryHandler::class,
            static function (ChildDefinition $definition, AsQueryHandler $attribute) use ($queryBusId): void {
                $bus = $attribute->bus ?? $queryBusId;
                $definition->addTag(
                    'messenger.message_handler',
                    array_filter([
                        'handles' => $attribute->query,
                        'bus' => $bus,
                    ], static fn ($value): bool => null !== $value)
                );
            }
        );

        $container->registerAttributeForAutoconfiguration(
            AsEventHandler::class,
            static function (ChildDefinition $definition, AsEventHandler $attribute) use ($eventBusId): void {
                $bus = $attribute->bus ?? $eventBusId;
                $definition->addTag(
                    'messenger.message_handler',
                    array_filter([
                        'handles' => $attribute->event,
                        'bus' => $bus,
                    ], static fn ($value): bool => null !== $value)
                );
            }
        );

        $this->registerHandlerInterfaceAutoconfiguration($container, CommandHandler::class, $commandBusId, 'command');
        $this->registerHandlerInterfaceAutoconfiguration($container, QueryHandler::class, $queryBusId, 'query');
        $this->registerHandlerInterfaceAutoconfiguration($container, EventHandler::class, $eventBusId, 'event');
    }

    private function registerHandlerInterfaceAutoconfiguration(ContainerBuilder $container, string $interface, ?string $busId, string $type): void
    {
        $container->registerForAutoconfiguration($interface)
            ->addTag(
                'somework_cqrs.handler_interface',
                array_filter([
                    'bus' => $busId,
                    'method' => '__invoke',
                    'type' => $type,
                ], static fn ($value): bool => null !== $value)
            );
    }

    /**
     * @param array{default: string, command?: string|null, query?: string|null, event?: string|null} $config
     */
    private function registerNamingStrategies(ContainerBuilder $container, array $config): void
    {
        $defaultId = $this->ensureServiceExists($container, $config['default']);
        $commandId = $this->ensureServiceExists($container, $config['command'] ?? $defaultId);
        $queryId = $this->ensureServiceExists($container, $config['query'] ?? $defaultId);
        $eventId = $this->ensureServiceExists($container, $config['event'] ?? $defaultId);

        $serviceMap = [
            'default' => new ServiceClosureArgument(new Reference($defaultId)),
            'command' => new ServiceClosureArgument(new Reference($commandId)),
            'query' => new ServiceClosureArgument(new Reference($queryId)),
            'event' => new ServiceClosureArgument(new Reference($eventId)),
        ];

        $locatorId = ServiceLocatorTagPass::register($container, $serviceMap);
        $container->setAlias('somework_cqrs.naming_locator', (string) $locatorId)->setPublic(false);
    }

    /**
     * @param array<string, array{default: string, map: array<string, string>}> $config
     */
    private function registerRetryPolicies(ContainerBuilder $container, array $config): void
    {
        foreach (['command', 'query', 'event'] as $type) {
            $this->registerServiceAlias($container, sprintf('somework_cqrs.retry.%s', $type), $config[$type]['default']);

            $serviceMap = [];
            foreach ($config[$type]['map'] as $messageClass => $serviceId) {
                $resolvedId = $this->ensureServiceExists($container, $serviceId);
                $serviceMap[$messageClass] = new ServiceClosureArgument(new Reference($resolvedId));
            }

            $locatorReference = ServiceLocatorTagPass::register($container, $serviceMap);
            $container->setAlias(sprintf('somework_cqrs.retry.%s_locator', $type), (string) $locatorReference)->setPublic(false);

            $resolverDefinition = new Definition(RetryPolicyResolver::class);
            $resolverDefinition->setArgument('$defaultPolicy', new Reference(sprintf('somework_cqrs.retry.%s', $type)));
            $resolverDefinition->setArgument('$policies', $locatorReference);
            $resolverDefinition->setPublic(false);

            $container->setDefinition(sprintf('somework_cqrs.retry.%s_resolver', $type), $resolverDefinition);
        }
    }

    /**
     * @param array{
     *     default: string,
     *     command: array{default: string|null, map: array<string, string>},
     *     query: array{default: string|null, map: array<string, string>},
     *     event: array{default: string|null, map: array<string, string>},
     * } $config
     */
    private function registerSerializers(ContainerBuilder $container, array $config): void
    {
        $defaultId = $this->ensureServiceExists($container, $config['default']);
        $container->setAlias('somework_cqrs.serializer.default', $defaultId)->setPublic(false);

        foreach (['command', 'query', 'event'] as $type) {
            $typeDefaultId = $config[$type]['default'];
            if (null === $typeDefaultId) {
                $resolvedTypeDefaultId = $defaultId;
            } else {
                $resolvedTypeDefaultId = $this->ensureServiceExists($container, $typeDefaultId);
            }

            $serviceMap = [
                MessageSerializerResolver::GLOBAL_DEFAULT_KEY => new ServiceClosureArgument(new Reference($defaultId)),
                MessageSerializerResolver::TYPE_DEFAULT_KEY => new ServiceClosureArgument(new Reference($resolvedTypeDefaultId)),
            ];

            foreach ($config[$type]['map'] as $messageClass => $serviceId) {
                $resolvedId = $this->ensureServiceExists($container, $serviceId);
                $serviceMap[$messageClass] = new ServiceClosureArgument(new Reference($resolvedId));
            }

            $locatorReference = ServiceLocatorTagPass::register($container, $serviceMap);
            $container->setAlias(sprintf('somework_cqrs.serializer.%s_locator', $type), (string) $locatorReference)->setPublic(false);

            $resolverDefinition = new Definition(MessageSerializerResolver::class);
            $resolverDefinition->setArgument('$serializers', $locatorReference);
            $resolverDefinition->setPublic(false);

            $container->setDefinition(sprintf('somework_cqrs.serializer.%s_resolver', $type), $resolverDefinition);
            $container->setAlias(sprintf('somework_cqrs.serializer.%s', $type), $resolvedTypeDefaultId)->setPublic(false);
        }
    }

    /**
     * @param array{
     *     default: string,
     *     command: array{default: string|null, map: array<string, string>},
     *     query: array{default: string|null, map: array<string, string>},
     *     event: array{default: string|null, map: array<string, string>},
     * } $config
     */
    private function registerMetadataProviders(ContainerBuilder $container, array $config): void
    {
        $defaultId = $this->ensureServiceExists($container, $config['default']);
        $container->setAlias('somework_cqrs.metadata.default', $defaultId)->setPublic(false);

        foreach (['command', 'query', 'event'] as $type) {
            $typeDefaultId = $config[$type]['default'];
            if (null === $typeDefaultId) {
                $resolvedTypeDefaultId = $defaultId;
            } else {
                $resolvedTypeDefaultId = $this->ensureServiceExists($container, $typeDefaultId);
            }

            $serviceMap = [
                MessageMetadataProviderResolver::GLOBAL_DEFAULT_KEY => new ServiceClosureArgument(new Reference($defaultId)),
                MessageMetadataProviderResolver::TYPE_DEFAULT_KEY => new ServiceClosureArgument(new Reference($resolvedTypeDefaultId)),
            ];

            foreach ($config[$type]['map'] as $messageClass => $serviceId) {
                $resolvedId = $this->ensureServiceExists($container, $serviceId);
                $serviceMap[$messageClass] = new ServiceClosureArgument(new Reference($resolvedId));
            }

            $locatorReference = ServiceLocatorTagPass::register($container, $serviceMap);
            $container->setAlias(sprintf('somework_cqrs.metadata.%s_locator', $type), (string) $locatorReference)->setPublic(false);

            $resolverDefinition = new Definition(MessageMetadataProviderResolver::class);
            $resolverDefinition->setArgument('$providers', $locatorReference);
            $resolverDefinition->setPublic(false);

            $container->setDefinition(sprintf('somework_cqrs.metadata.%s_resolver', $type), $resolverDefinition);
            $container->setAlias(sprintf('somework_cqrs.metadata.%s', $type), $resolvedTypeDefaultId)->setPublic(false);
        }
    }

    /**
     * @param array<string, array{default: list<string>, map: array<string, list<string>>, stamp: string}> $config
     */
    private function registerTransports(ContainerBuilder $container, array $config): void
    {
        $configuredTransportNames = [];
        $stampTypes = [];
        $mapping = [];

        foreach (['command', 'command_async', 'query', 'event', 'event_async'] as $type) {
            $serviceMap = [];
            $typeConfig = $config[$type];

            $stampTypes[$type] = $typeConfig['stamp'];
            $mapping[$type] = [
                'default' => $typeConfig['default'],
                'map' => $typeConfig['map'],
            ];

            if ([] !== $typeConfig['default']) {
                $defaultServiceId = sprintf('somework_cqrs.transports.%s.default', $type);

                $defaultDefinition = new Definition(ArrayObject::class);
                $defaultDefinition->setArguments([$typeConfig['default']]);
                $defaultDefinition->setPublic(false);

                $container->setDefinition($defaultServiceId, $defaultDefinition);

                $serviceMap[MessageTransportResolver::DEFAULT_KEY] = new ServiceClosureArgument(new Reference($defaultServiceId));

                foreach ($typeConfig['default'] as $transportName) {
                    $configuredTransportNames[] = (string) $transportName;
                }
            }

            foreach ($typeConfig['map'] as $messageClass => $transports) {
                $serviceId = sprintf('somework_cqrs.transports.%s.%s', $type, md5($messageClass));

                $definition = new Definition(ArrayObject::class);
                $definition->setArguments([$transports]);
                $definition->setPublic(false);

                $container->setDefinition($serviceId, $definition);

                $serviceMap[$messageClass] = new ServiceClosureArgument(new Reference($serviceId));

                foreach ($transports as $transportName) {
                    $configuredTransportNames[] = (string) $transportName;
                }
            }

            $locatorReference = ServiceLocatorTagPass::register($container, $serviceMap);
            $container->setAlias(sprintf('somework_cqrs.transports.%s_locator', $type), (string) $locatorReference)->setPublic(false);

            $resolverDefinition = new Definition(MessageTransportResolver::class);
            $resolverDefinition->setArgument('$transports', $locatorReference);
            $resolverDefinition->setPublic(false);

            $container->setDefinition(sprintf('somework_cqrs.transports.%s_resolver', $type), $resolverDefinition);
        }

        $configuredTransportNames = array_values(array_unique($configuredTransportNames));

        $container->setParameter('somework_cqrs.transport_names', $configuredTransportNames);
        $container->setParameter('somework_cqrs.transport_mapping', $mapping);
        $container->setParameter('somework_cqrs.transport_stamp_types', $stampTypes);

        $providerDefinition = new Definition(TransportMappingProvider::class);
        $providerDefinition->setArgument('$mapping', $mapping);
        $providerDefinition->setPublic(false);

        $container->setDefinition('somework_cqrs.transport_mapping_provider', $providerDefinition);
        $container->setAlias(TransportMappingProvider::class, 'somework_cqrs.transport_mapping_provider')->setPublic(false);
    }

    /**
     * @param array{
     *     command: array{default: string, map: array<string, string>},
     *     event: array{default: string, map: array<string, string>},
     * } $config
     */
    private function registerDispatchModeDecider(ContainerBuilder $container, array $config): void
    {
        $definition = new Definition(DispatchModeDecider::class);
        $definition->setArgument('$commandDefault', DispatchMode::from($config['command']['default']));
        $definition->setArgument('$eventDefault', DispatchMode::from($config['event']['default']));
        $definition->setArgument(
            '$commandMap',
            array_map(static fn (string $mode): DispatchMode => DispatchMode::from($mode), $config['command']['map'])
        );
        $definition->setArgument(
            '$eventMap',
            array_map(static fn (string $mode): DispatchMode => DispatchMode::from($mode), $config['event']['map'])
        );
        $definition->setPublic(false);

        $container->setDefinition('somework_cqrs.dispatch_mode_decider', $definition);
        $container->setAlias(DispatchModeDecider::class, 'somework_cqrs.dispatch_mode_decider')->setPublic(false);
    }

    /**
     * @param array{
     *     buses: array{
     *         command?: string|null,
     *         command_async?: string|null,
     *         query?: string|null,
     *         event?: string|null,
     *         event_async?: string|null,
     *     },
     *     dispatch_modes: array{
     *         command: array{default: string, map: array<string, string>},
     *         event: array{default: string, map: array<string, string>},
     *     },
     *     transports: array{
     *         command: array{default: list<string>, map: array<string, list<string>>},
     *         command_async: array{default: list<string>, map: array<string, list<string>>},
     *         query: array{default: list<string>, map: array<string, list<string>>},
     *         event: array{default: list<string>, map: array<string, list<string>>},
     *         event_async: array{default: list<string>, map: array<string, list<string>>},
     *     },
     * } $config
     */
    private function guardAsyncBusConfiguration(array $config): void
    {
        $commandAsyncBus = $config['buses']['command_async'] ?? null;
        $eventAsyncBus = $config['buses']['event_async'] ?? null;

        $commandAsyncSources = $this->collectAsyncSources(
            $config['dispatch_modes']['command'],
            $config['transports']['command_async']
        );
        if (null === $commandAsyncBus && $this->hasAsyncConfiguration($commandAsyncSources)) {
            $this->throwMissingAsyncBusException('command', $commandAsyncSources, 'command_async');
        }

        $eventAsyncSources = $this->collectAsyncSources(
            $config['dispatch_modes']['event'],
            $config['transports']['event_async']
        );
        if (null === $eventAsyncBus && $this->hasAsyncConfiguration($eventAsyncSources)) {
            $this->throwMissingAsyncBusException('event', $eventAsyncSources, 'event_async');
        }
    }

    /**
     * @param array{default: string, map: array<string, string>}             $dispatchConfig
     * @param array{default: list<string>, map: array<string, list<string>>} $transportConfig
     *
     * @return array{
     *     dispatch_default: bool,
     *     dispatch_messages: list<string>,
     *     transport_default: list<string>,
     *     transport_messages: array<string, list<string>>,
     * }
     */
    private function collectAsyncSources(array $dispatchConfig, array $transportConfig): array
    {
        $dispatchMessages = [];

        foreach ($dispatchConfig['map'] as $messageClass => $mode) {
            if (DispatchMode::ASYNC->value === $mode) {
                $dispatchMessages[] = $messageClass;
            }
        }

        return [
            'dispatch_default' => DispatchMode::ASYNC->value === $dispatchConfig['default'],
            'dispatch_messages' => $dispatchMessages,
            'transport_default' => $transportConfig['default'],
            'transport_messages' => array_filter(
                $transportConfig['map'],
                static fn (array $transports): bool => [] !== $transports
            ),
        ];
    }

    /**
     * @param array{
     *     dispatch_default: bool,
     *     dispatch_messages: list<string>,
     *     transport_default: list<string>,
     *     transport_messages: array<string, list<string>>,
     * } $sources
     */
    private function hasAsyncConfiguration(array $sources): bool
    {
        return $sources['dispatch_default']
            || [] !== $sources['dispatch_messages']
            || [] !== $sources['transport_default']
            || [] !== $sources['transport_messages'];
    }

    /**
     * @param array{
     *     dispatch_default: bool,
     *     dispatch_messages: list<string>,
     *     transport_default: list<string>,
     *     transport_messages: array<string, list<string>>,
     * } $sources
     */
    private function throwMissingAsyncBusException(string $type, array $sources, string $busKey): void
    {
        $typeLabel = 'command' === $type ? 'commands' : 'events';

        $parts = [];
        if ($sources['dispatch_default']) {
            $parts[] = 'the default dispatch mode is "async"';
        }
        if ([] !== $sources['dispatch_messages']) {
            $parts[] = sprintf('async dispatch mode map entries: %s', implode(', ', $sources['dispatch_messages']));
        }
        if ([] !== $sources['transport_default']) {
            $parts[] = sprintf('async transport defaults: %s', implode(', ', $sources['transport_default']));
        }
        if ([] !== $sources['transport_messages']) {
            $entries = [];
            foreach ($sources['transport_messages'] as $messageClass => $transports) {
                $entries[] = sprintf('%s => [%s]', $messageClass, implode(', ', $transports));
            }

            $parts[] = sprintf('async transport map entries: %s', implode(', ', $entries));
        }

        $details = implode(' and ', $parts);

        $message = sprintf(
            'Asynchronous dispatch is configured for %s (%s), but "somework_cqrs.buses.%s" is null. Define the Messenger bus id used for async %s before the container is compiled.',
            $typeLabel,
            $details,
            $busKey,
            $typeLabel,
        );

        throw new InvalidConfigurationException($message);
    }

    /**
     * @param array{
     *     command: array{default: bool, map: array<string, bool>},
     *     event: array{default: bool, map: array<string, bool>},
     * } $config
     */
    private function registerDispatchAfterCurrentBusDecider(ContainerBuilder $container, array $config): void
    {
        $commandLocator = $this->registerBooleanLocator($container, 'command', $config['command']['map']);
        $eventLocator = $this->registerBooleanLocator($container, 'event', $config['event']['map']);

        $definition = new Definition(DispatchAfterCurrentBusDecider::class);
        $definition->setArgument('$commandDefault', $config['command']['default']);
        $definition->setArgument('$commandToggles', $commandLocator);
        $definition->setArgument('$eventDefault', $config['event']['default']);
        $definition->setArgument('$eventToggles', $eventLocator);
        $definition->setPublic(false);

        $container->setDefinition('somework_cqrs.dispatch_after_current_bus_decider', $definition);
        $container->setAlias(DispatchAfterCurrentBusDecider::class, 'somework_cqrs.dispatch_after_current_bus_decider')->setPublic(false);
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
    private function registerStampsDecider(ContainerBuilder $container, array $buses): void
    {
        $commandRetryDefinition = new Definition(RetryPolicyStampDecider::class);
        $commandRetryDefinition->setArgument('$retryPolicies', new Reference('somework_cqrs.retry.command_resolver'));
        $commandRetryDefinition->setArgument('$messageType', Command::class);
        $commandRetryDefinition->addTag('somework_cqrs.dispatch_stamp_decider', ['priority' => 200]);
        $commandRetryDefinition->setPublic(false);

        $container->setDefinition('somework_cqrs.stamp_decider.command_retry', $commandRetryDefinition);

        $commandSerializerDefinition = new Definition(MessageSerializerStampDecider::class);
        $commandSerializerDefinition->setArgument('$serializers', new Reference('somework_cqrs.serializer.command_resolver'));
        $commandSerializerDefinition->setArgument('$messageType', Command::class);
        $commandSerializerDefinition->addTag('somework_cqrs.dispatch_stamp_decider', ['priority' => 150]);
        $commandSerializerDefinition->setPublic(false);

        $container->setDefinition('somework_cqrs.stamp_decider.command_serializer', $commandSerializerDefinition);

        $queryRetryDefinition = new Definition(RetryPolicyStampDecider::class);
        $queryRetryDefinition->setArgument('$retryPolicies', new Reference('somework_cqrs.retry.query_resolver'));
        $queryRetryDefinition->setArgument('$messageType', Query::class);
        $queryRetryDefinition->addTag('somework_cqrs.dispatch_stamp_decider', ['priority' => 200]);
        $queryRetryDefinition->setPublic(false);

        $container->setDefinition('somework_cqrs.stamp_decider.query_retry', $queryRetryDefinition);

        $querySerializerDefinition = new Definition(MessageSerializerStampDecider::class);
        $querySerializerDefinition->setArgument('$serializers', new Reference('somework_cqrs.serializer.query_resolver'));
        $querySerializerDefinition->setArgument('$messageType', Query::class);
        $querySerializerDefinition->addTag('somework_cqrs.dispatch_stamp_decider', ['priority' => 150]);
        $querySerializerDefinition->setPublic(false);

        $container->setDefinition('somework_cqrs.stamp_decider.query_serializer', $querySerializerDefinition);

        $queryMetadataDefinition = new Definition(MessageMetadataStampDecider::class);
        $queryMetadataDefinition->setArgument('$providers', new Reference('somework_cqrs.metadata.query_resolver'));
        $queryMetadataDefinition->setArgument('$messageType', Query::class);
        $queryMetadataDefinition->addTag('somework_cqrs.dispatch_stamp_decider', ['priority' => 125]);
        $queryMetadataDefinition->setPublic(false);

        $container->setDefinition('somework_cqrs.stamp_decider.query_metadata', $queryMetadataDefinition);

        $commandMetadataDefinition = new Definition(MessageMetadataStampDecider::class);
        $commandMetadataDefinition->setArgument('$providers', new Reference('somework_cqrs.metadata.command_resolver'));
        $commandMetadataDefinition->setArgument('$messageType', Command::class);
        $commandMetadataDefinition->addTag('somework_cqrs.dispatch_stamp_decider', ['priority' => 125]);
        $commandMetadataDefinition->setPublic(false);

        $container->setDefinition('somework_cqrs.stamp_decider.command_metadata', $commandMetadataDefinition);

        $eventRetryDefinition = new Definition(RetryPolicyStampDecider::class);
        $eventRetryDefinition->setArgument('$retryPolicies', new Reference('somework_cqrs.retry.event_resolver'));
        $eventRetryDefinition->setArgument('$messageType', Event::class);
        $eventRetryDefinition->addTag('somework_cqrs.dispatch_stamp_decider', ['priority' => 200]);
        $eventRetryDefinition->setPublic(false);

        $container->setDefinition('somework_cqrs.stamp_decider.event_retry', $eventRetryDefinition);

        $eventSerializerDefinition = new Definition(MessageSerializerStampDecider::class);
        $eventSerializerDefinition->setArgument('$serializers', new Reference('somework_cqrs.serializer.event_resolver'));
        $eventSerializerDefinition->setArgument('$messageType', Event::class);
        $eventSerializerDefinition->addTag('somework_cqrs.dispatch_stamp_decider', ['priority' => 150]);
        $eventSerializerDefinition->setPublic(false);

        $container->setDefinition('somework_cqrs.stamp_decider.event_serializer', $eventSerializerDefinition);

        $eventMetadataDefinition = new Definition(MessageMetadataStampDecider::class);
        $eventMetadataDefinition->setArgument('$providers', new Reference('somework_cqrs.metadata.event_resolver'));
        $eventMetadataDefinition->setArgument('$messageType', Event::class);
        $eventMetadataDefinition->addTag('somework_cqrs.dispatch_stamp_decider', ['priority' => 125]);
        $eventMetadataDefinition->setPublic(false);

        $container->setDefinition('somework_cqrs.stamp_decider.event_metadata', $eventMetadataDefinition);

        $stampFactoryDefinition = new Definition(MessageTransportStampFactory::class);
        $stampFactoryDefinition->setPublic(false);

        $container->setDefinition('somework_cqrs.transport_stamp_factory', $stampFactoryDefinition);
        $container->setAlias(MessageTransportStampFactory::class, 'somework_cqrs.transport_stamp_factory')->setPublic(false);

        $transportDefinition = new Definition(MessageTransportStampDecider::class);
        $transportDefinition->setArgument('$stampFactory', new Reference('somework_cqrs.transport_stamp_factory'));
        $transportDefinition->setArgument('$stampTypes', '%somework_cqrs.transport_stamp_types%');
        $transportDefinition->setArgument('$commandTransports', new Reference('somework_cqrs.transports.command_resolver'));
        $transportDefinition->setArgument(
            '$commandAsyncTransports',
            isset($buses['command_async']) && null !== $buses['command_async']
                ? new Reference('somework_cqrs.transports.command_async_resolver')
                : null,
        );
        $transportDefinition->setArgument('$queryTransports', new Reference('somework_cqrs.transports.query_resolver'));
        $transportDefinition->setArgument('$eventTransports', new Reference('somework_cqrs.transports.event_resolver'));
        $transportDefinition->setArgument(
            '$eventAsyncTransports',
            isset($buses['event_async']) && null !== $buses['event_async']
                ? new Reference('somework_cqrs.transports.event_async_resolver')
                : null,
        );
        $transportDefinition->addTag('somework_cqrs.dispatch_stamp_decider', ['priority' => 175]);
        $transportDefinition->setPublic(false);

        $container->setDefinition('somework_cqrs.stamp_decider.message_transport', $transportDefinition);

        $dispatchAfterDefinition = new Definition(DispatchAfterCurrentBusStampDecider::class);
        $dispatchAfterDefinition->setArgument('$decider', new Reference('somework_cqrs.dispatch_after_current_bus_decider'));
        $dispatchAfterDefinition->addTag('somework_cqrs.dispatch_stamp_decider', ['priority' => 0]);
        $dispatchAfterDefinition->setPublic(false);

        $container->setDefinition('somework_cqrs.dispatch_after_current_bus_stamp_decider', $dispatchAfterDefinition);

        $definition = new Definition(StampsDecider::class);
        $definition->setArgument('$deciders', new TaggedIteratorArgument('somework_cqrs.dispatch_stamp_decider'));
        $definition->setPublic(false);

        $container->setDefinition('somework_cqrs.stamps_decider', $definition);
    }

    /**
     * @param array<string, bool> $map
     */
    private function registerBooleanLocator(ContainerBuilder $container, string $type, array $map): Reference
    {
        $serviceMap = [];

        foreach ($map as $messageClass => $enabled) {
            $serviceId = sprintf('somework_cqrs.async.dispatch_after_current_bus.%s.%s', $type, md5($messageClass));

            $definition = new Definition();
            $definition->setFactory([self::class, 'createBooleanToggle']);
            $definition->setArguments([$enabled]);
            $definition->setPublic(false);

            $container->setDefinition($serviceId, $definition);
            $serviceMap[$messageClass] = new ServiceClosureArgument(new Reference($serviceId));
        }

        $locatorReference = ServiceLocatorTagPass::register($container, $serviceMap);
        $container->setAlias(sprintf('somework_cqrs.async.dispatch_after_current_bus.%s_locator', $type), (string) $locatorReference)->setPublic(false);

        return $locatorReference;
    }

    public static function createBooleanToggle(bool $value): bool
    {
        return $value;
    }

    private function registerServiceAlias(ContainerBuilder $container, string $aliasId, string $serviceId): void
    {
        $serviceId = $this->ensureServiceExists($container, $serviceId);

        $container->setAlias($aliasId, $serviceId)->setPublic(false);
    }

    private function ensureServiceExists(ContainerBuilder $container, string $serviceId): string
    {
        if (!$container->has($serviceId) && class_exists($serviceId)) {
            $definition = new Definition($serviceId);
            $definition->setAutowired(true);
            $definition->setAutoconfigured(true);
            $container->setDefinition($serviceId, $definition);
        }

        return $serviceId;
    }
}
