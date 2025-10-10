<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Bus\QueryBus;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

final class BusWiringRegistrar
{
    /**
     * @param array{
     *     command?: string|null,
     *     command_async?: string|null,
     *     query?: string|null,
     *     event?: string|null,
     *     event_async?: string|null,
     * } $buses
     */
    public function register(ContainerBuilder $container, array $buses, string $defaultBusId): void
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
}
