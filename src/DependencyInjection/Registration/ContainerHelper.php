<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function md5;
use function sprintf;

/** @internal */
final class ContainerHelper
{
    /** Parameter listing the services the configuration names, checked by ValidateConfiguredServicesPass. */
    public const CONFIGURED_SERVICES = 'somework_cqrs.configured_services';

    /**
     * Remembers that the option at $path (below "somework_cqrs.") names the service $serviceId, so
     * a missing service is reported with that option instead of an internal service id.
     */
    /**
     * @param class-string|null $interface What the service must implement
     */
    public function recordConfiguredService(ContainerBuilder $container, string $path, string $serviceId, ?string $interface = null): void
    {
        /** @var list<array{string, string, class-string|null}> $services */
        $services = $container->hasParameter(self::CONFIGURED_SERVICES) ? $container->getParameter(self::CONFIGURED_SERVICES) : [];
        $services[] = [$path, $serviceId, $interface];
        $container->setParameter(self::CONFIGURED_SERVICES, $services);
    }

    /**
     * {@see ensureServiceExists()} for a service the option at $path names.
     */
    /**
     * @param class-string|null $interface What the service must implement
     */
    public function configuredService(ContainerBuilder $container, string $path, string $serviceId, ?string $interface = null): string
    {
        $this->recordConfiguredService($container, $path, $serviceId, $interface);

        return $this->ensureServiceExists($container, $serviceId);
    }

    /**
     * Registers a service for a class name used as service id, unless it is already defined.
     * Abstract classes are left alone: they cannot be instantiated, and the missing service is
     * reported by the container instead.
     */
    public function ensureServiceExists(ContainerBuilder $container, string $serviceId): string
    {
        if (!$container->has($serviceId) && class_exists($serviceId) && !(new \ReflectionClass($serviceId))->isAbstract()) {
            $definition = new Definition($serviceId);
            $definition->setAutowired(true);
            $definition->setAutoconfigured(true);
            $definition->setPublic(false);

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
            $serviceId = sprintf('somework_cqrs.dispatch_after_current_bus.%s.%s', $type, md5($messageClass));

            $definition = new Definition('bool');
            $definition->setFactory([self::class, 'createBooleanToggle']);
            $definition->setArguments([$enabled]);
            $definition->setPublic(false);

            $container->setDefinition($serviceId, $definition);
            $serviceMap[$messageClass] = new Reference($serviceId);
        }

        $locatorReference = ServiceLocatorTagPass::register($container, $serviceMap);
        $container->setAlias(sprintf('somework_cqrs.dispatch_after_current_bus.%s_locator', $type), (string) $locatorReference)->setPublic(false);

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
        return isset($buses[$messageType])
            ? $this->createResolverReference('transports', $messageType)
            : null;
    }

    public static function createBooleanToggle(bool $value): bool
    {
        return $value;
    }
}
