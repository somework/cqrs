<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class NamingRegistrar
{
    public function __construct(private readonly ContainerHelper $helper)
    {
    }

    /**
     * @param array{default: string, command?: string|null, query?: string|null, event?: string|null} $config
     */
    public function register(ContainerBuilder $container, array $config): void
    {
        $defaultId = $this->helper->ensureServiceExists($container, $config['default']);
        $commandId = $this->helper->ensureServiceExists($container, $config['command'] ?? $defaultId);
        $queryId = $this->helper->ensureServiceExists($container, $config['query'] ?? $defaultId);
        $eventId = $this->helper->ensureServiceExists($container, $config['event'] ?? $defaultId);

        $serviceMap = [
            'default' => new ServiceClosureArgument(new Reference($defaultId)),
            'command' => new ServiceClosureArgument(new Reference($commandId)),
            'query' => new ServiceClosureArgument(new Reference($queryId)),
            'event' => new ServiceClosureArgument(new Reference($eventId)),
        ];

        $locatorId = ServiceLocatorTagPass::register($container, $serviceMap);
        $container->setAlias('somework_cqrs.naming_locator', (string) $locatorId)->setPublic(false);
    }
}
