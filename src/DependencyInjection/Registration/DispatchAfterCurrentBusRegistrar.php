<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusDecider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class DispatchAfterCurrentBusRegistrar
{
    public function __construct(private readonly ContainerHelper $helper)
    {
    }

    /**
     * @param array{
     *     command: array{default: bool, map: array<string, bool>},
     *     event: array{default: bool, map: array<string, bool>},
     * } $config
     */
    public function register(ContainerBuilder $container, array $config): void
    {
        $commandLocator = $this->helper->registerBooleanLocator($container, 'command', $config['command']['map']);
        $eventLocator = $this->helper->registerBooleanLocator($container, 'event', $config['event']['map']);

        $definition = new Definition(DispatchAfterCurrentBusDecider::class);
        $definition->setArgument('$commandDefault', $config['command']['default']);
        $definition->setArgument('$commandToggles', $commandLocator);
        $definition->setArgument('$eventDefault', $config['event']['default']);
        $definition->setArgument('$eventToggles', $eventLocator);
        $definition->setPublic(false);

        $container->setDefinition('somework_cqrs.dispatch_after_current_bus_decider', $definition);
        $container->setAlias(DispatchAfterCurrentBusDecider::class, 'somework_cqrs.dispatch_after_current_bus_decider')->setPublic(false);
    }
}
