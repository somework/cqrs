<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function md5;
use function sprintf;

final class ContainerHelper
{
    public function ensureServiceExists(ContainerBuilder $container, string $serviceId): string
    {
        if (!$container->has($serviceId) && class_exists($serviceId)) {
            $definition = new Definition($serviceId);
            $definition->setAutowired(true);
            $definition->setAutoconfigured(true);

            $container->setDefinition($serviceId, $definition);
        }

        return $serviceId;
    }

    public function registerServiceAlias(ContainerBuilder $container, string $aliasId, string $serviceId): void
    {
        $serviceId = $this->ensureServiceExists($container, $serviceId);

        $container->setAlias($aliasId, $serviceId)->setPublic(false);
    }

    /**
     * @param array<string, bool> $map
     */
    public function registerBooleanLocator(ContainerBuilder $container, string $type, array $map): Reference
    {
        $serviceMap = [];

        foreach ($map as $messageClass => $enabled) {
            $serviceId = sprintf('somework_cqrs.async.dispatch_after_current_bus.%s.%s', $type, md5($messageClass));

            $definition = new Definition('bool');
            $definition->setFactory([CqrsExtension::class, 'createBooleanToggle']);
            $definition->setArguments([$enabled]);
            $definition->setPublic(false);

            $container->setDefinition($serviceId, $definition);
            $serviceMap[$messageClass] = new ServiceClosureArgument(new Reference($serviceId));
        }

        $locatorReference = ServiceLocatorTagPass::register($container, $serviceMap);
        $container->setAlias(sprintf('somework_cqrs.async.dispatch_after_current_bus.%s_locator', $type), (string) $locatorReference)->setPublic(false);

        return $locatorReference;
    }

    public function createResolverReference(string $type, string $messageType): Reference
    {
        return new Reference(sprintf('somework_cqrs.%s.%s_resolver', $type, $messageType));
    }

    /**
     * @param array{command?: string|null, command_async?: string|null, query?: string|null, event?: string|null, event_async?: string|null} $buses
     */
    public function createOptionalTransportResolverReference(string $messageType, array $buses): ?Reference
    {
        return (isset($buses[$messageType]) && null !== $buses[$messageType])
            ? $this->createResolverReference('transports', $messageType)
            : null;
    }
}
