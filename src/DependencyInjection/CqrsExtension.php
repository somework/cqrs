<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection;

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
use SomeWork\CqrsBundle\Contract\QueryHandler;
use SomeWork\CqrsBundle\Messenger\EnvelopeAwareHandlersLocator;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusDecider;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusStampDecider;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
use SomeWork\CqrsBundle\Support\MessageSerializerStampDecider;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Support\RetryPolicyStampDecider;
use SomeWork\CqrsBundle\Support\StampDecider;
use SomeWork\CqrsBundle\Support\StampsDecider;
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

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.php');

        $container->registerForAutoconfiguration(StampDecider::class)
            ->addTag('somework_cqrs.dispatch_stamp_decider');

        $this->registerHandlerAutoconfiguration($container, $config['buses'], $defaultBusId);
        $this->registerNamingStrategies($container, $config['naming']);
        $this->registerRetryPolicies($container, $config['retry_policies']);
        $this->registerSerializers($container, $config['serialization']);
        $this->registerDispatchModeDecider($container, $config['dispatch_modes']);
        $this->registerDispatchAfterCurrentBusDecider($container, $config['async']['dispatch_after_current_bus']);
        $this->registerStampsDecider($container);
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
            $queryBusDefinition->setArgument('$retryPolicies', new Reference('somework_cqrs.retry.query_resolver'));
            $queryBusDefinition->setArgument('$serializers', new Reference('somework_cqrs.serializer.query_resolver'));
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
            $locatorId = sprintf('%s.messenger.handlers_locator', $busId);

            $decoratorId = sprintf('somework_cqrs.envelope_aware_handlers_locator.%s', md5($locatorId));

            $container->register($decoratorId, EnvelopeAwareHandlersLocator::class)
                ->setDecoratedService($locatorId)
                ->setArgument('$decorated', new Reference($decoratorId.'.inner'));
        }
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
    }

    private function registerStampsDecider(ContainerBuilder $container): void
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
