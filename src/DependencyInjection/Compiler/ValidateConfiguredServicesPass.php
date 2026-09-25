<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use SomeWork\CqrsBundle\DependencyInjection\Registration\ContainerHelper;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function class_exists;
use function is_a;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * Reports a service the configuration names but the container lacks with the option that names
 * it, e.g. "somework_cqrs.retry_policies.command.map.App\Command\Pay", instead of Symfony's
 * "somework_cqrs.retry.command_resolver has a dependency on a non-existent service".
 *
 * Runs once every extension registered its services, then drops the list.
 *
 * @internal
 */
final class ValidateConfiguredServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(ContainerHelper::CONFIGURED_SERVICES)) {
            return;
        }

        $services = $container->getParameter(ContainerHelper::CONFIGURED_SERVICES);
        $container->getParameterBag()->remove(ContainerHelper::CONFIGURED_SERVICES);

        foreach (is_array($services) ? $services : [] as $entry) {
            [$path, $serviceId] = $entry;
            $interface = $entry[2] ?? null;
            if ($container->has($serviceId)) {
                // Otherwise the first dispatch fails with a TypeError about an internal service.
                // A service defined by its class name has no class until ResolveClassPass.
                $class = $container->findDefinition($serviceId)->getClass() ?? $serviceId;
                $class = $container->getParameterBag()->resolveValue($class);
                if (null !== $interface && is_string($class) && class_exists($class) && !is_a($class, $interface, true)) {
                    throw new InvalidConfigurationException(sprintf('The service "%s" configured at "somework_cqrs.%s" must implement %s, %s does not.', $serviceId, $path, $interface, $class));
                }

                continue;
            }

            throw new InvalidConfigurationException(match (true) {
                str_starts_with($serviceId, 'limiter.') => sprintf('The rate limiter "%s" configured at "somework_cqrs.%s" does not exist. Define it under "framework.rate_limiter".', substr($serviceId, 8), $path), str_starts_with($serviceId, 'doctrine.dbal.') => sprintf('The Doctrine DBAL connection service "%s" for "somework_cqrs.%s" does not exist. Configure the connection under "doctrine.dbal.connections".', $serviceId, $path), default => sprintf('The service "%s" configured at "somework_cqrs.%s" does not exist.', $serviceId, $path),
            });
        }
    }
}
