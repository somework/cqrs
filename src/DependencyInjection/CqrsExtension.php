<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Attribute\AsQueryHandler;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Bus\QueryBus;
use SomeWork\CqrsBundle\Contract\CommandHandler;
use SomeWork\CqrsBundle\Contract\EventHandler;
use SomeWork\CqrsBundle\Contract\QueryHandler;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Definition;

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

        $this->registerHandlerAutoconfiguration($container, $config['buses'], $defaultBusId);
        $this->registerNamingStrategies($container, $config['naming']);
        $this->registerRetryPolicies($container, $config['retry_policies']);
        $this->registerSerializers($container, $config['serialization']);
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

            $commandBusDefinition->setArgument('$retryPolicy', new Reference('somework_cqrs.retry.command'));
            $commandBusDefinition->setArgument('$serializer', new Reference('somework_cqrs.serializer.command'));
        }

        if ($container->hasDefinition(QueryBus::class)) {
            $queryBusDefinition = $container->getDefinition(QueryBus::class);
            $queryBusDefinition->setArgument('$bus', new Reference($buses['query'] ?? $defaultBusId));
            $queryBusDefinition->setArgument('$retryPolicy', new Reference('somework_cqrs.retry.query'));
            $queryBusDefinition->setArgument('$serializer', new Reference('somework_cqrs.serializer.query'));
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

            $eventBusDefinition->setArgument('$retryPolicy', new Reference('somework_cqrs.retry.event'));
            $eventBusDefinition->setArgument('$serializer', new Reference('somework_cqrs.serializer.event'));
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
     * @param array{command: string, query: string, event: string} $config
     */
    private function registerRetryPolicies(ContainerBuilder $container, array $config): void
    {
        $this->registerServiceAlias($container, 'somework_cqrs.retry.command', $config['command']);
        $this->registerServiceAlias($container, 'somework_cqrs.retry.query', $config['query']);
        $this->registerServiceAlias($container, 'somework_cqrs.retry.event', $config['event']);
    }

    /**
     * @param array{command: string, query: string, event: string} $config
     */
    private function registerSerializers(ContainerBuilder $container, array $config): void
    {
        $this->registerServiceAlias($container, 'somework_cqrs.serializer.command', $config['command']);
        $this->registerServiceAlias($container, 'somework_cqrs.serializer.query', $config['query']);
        $this->registerServiceAlias($container, 'somework_cqrs.serializer.event', $config['event']);
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
