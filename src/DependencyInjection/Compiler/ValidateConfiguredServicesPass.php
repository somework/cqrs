<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use SomeWork\CqrsBundle\DependencyInjection\Registration\ContainerHelper;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_array;
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

        foreach (is_array($services) ? $services : [] as [$path, $serviceId]) {
            if ($container->has($serviceId)) {
                continue;
            }

            throw new InvalidConfigurationException(match (true) {
                str_starts_with($serviceId, 'limiter.') => sprintf('The rate limiter "%s" configured at "somework_cqrs.%s" does not exist. Define it under "framework.rate_limiter".', substr($serviceId, 8), $path), str_starts_with($serviceId, 'doctrine.dbal.') => sprintf('The Doctrine DBAL connection service "%s" for "somework_cqrs.%s" does not exist. Configure the connection under "doctrine.dbal.connections".', $serviceId, $path), default => sprintf('The service "%s" configured at "somework_cqrs.%s" does not exist.', $serviceId, $path),
            });
        }
    }
}
