<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\DispatchModeDecider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function array_map;

final class DispatchModeRegistrar
{
    public function register(ContainerBuilder $container, array $config): void
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
}
