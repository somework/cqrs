<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Attribute\AsQueryHandler;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Bus\QueryBus;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

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

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.php');

        $this->registerHandlerAttributes($container);
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
        }

        if ($container->hasDefinition(QueryBus::class)) {
            $queryBusDefinition = $container->getDefinition(QueryBus::class);
            $queryBusDefinition->setArgument('$bus', new Reference($buses['query'] ?? $defaultBusId));
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
        }
    }

    private function registerHandlerAttributes(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            AsCommandHandler::class,
            static function (ChildDefinition $definition, AsCommandHandler $attribute): void {
                $definition->addTag(
                    'messenger.message_handler',
                    array_filter([
                        'handles' => $attribute->command,
                        'bus' => $attribute->bus,
                    ], static fn ($value): bool => null !== $value)
                );
            }
        );

        $container->registerAttributeForAutoconfiguration(
            AsQueryHandler::class,
            static function (ChildDefinition $definition, AsQueryHandler $attribute): void {
                $definition->addTag(
                    'messenger.message_handler',
                    array_filter([
                        'handles' => $attribute->query,
                        'bus' => $attribute->bus,
                    ], static fn ($value): bool => null !== $value)
                );
            }
        );

        $container->registerAttributeForAutoconfiguration(
            AsEventHandler::class,
            static function (ChildDefinition $definition, AsEventHandler $attribute): void {
                $definition->addTag(
                    'messenger.message_handler',
                    array_filter([
                        'handles' => $attribute->event,
                        'bus' => $attribute->bus,
                    ], static fn ($value): bool => null !== $value)
                );
            }
        );
    }
}
