<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_keys;
use function implode;
use function is_string;
use function sort;
use function sprintf;
use function str_ends_with;

/**
 * Fails the build when "somework_cqrs.buses", or "somework_cqrs.default_bus" when a facade falls
 * back to it, names a service that is not a Messenger bus.
 *
 * Without it, a typo in an async bus id only surfaced at runtime as "asynchronous bus is not
 * configured". Runs before CqrsHandlerPass, which registers handlers on these buses.
 *
 * @internal
 */
final class ValidateBusIdsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('somework_cqrs.default_bus')) {
            return;
        }

        $usesDefaultBus = false;
        foreach (CqrsBusIds::BUS_KEYS as $key) {
            $parameter = 'somework_cqrs.bus.'.$key;
            $busId = $container->hasParameter($parameter) ? $container->getParameter($parameter) : null;

            if (!is_string($busId) || '' === $busId) {
                // The command, query and event facades fall back to the default bus.
                $usesDefaultBus = $usesDefaultBus || !str_ends_with($key, '_async');

                continue;
            }

            if (!$this->isBus($container, $busId)) {
                $this->fail($container, 'buses.'.$key, $busId);
            }
        }

        // Without any Messenger bus (Messenger disabled), the missing default bus is not the problem to report.
        $defaultBus = $container->getParameter('somework_cqrs.default_bus');
        if ($usesDefaultBus && is_string($defaultBus) && '' !== $defaultBus && !$this->isBus($container, $defaultBus)
            && ($container->has($defaultBus) || [] !== $container->findTaggedServiceIds('messenger.bus'))) {
            $this->fail($container, 'default_bus', $defaultBus);
        }
    }

    private function fail(ContainerBuilder $container, string $option, string $busId): never
    {
        $known = array_keys($container->findTaggedServiceIds('messenger.bus'));
        sort($known);

        throw new InvalidConfigurationException(sprintf('"somework_cqrs.%s" is "%s", which is not a Messenger bus. Known buses: %s. Declare it under "framework.messenger.buses".', $option, $busId, [] === $known ? 'none' : implode(', ', $known)));
    }

    private function isBus(ContainerBuilder $container, string $busId): bool
    {
        $serviceId = CqrsBusIds::resolveAlias($container, $busId);

        return $container->hasDefinition($serviceId) && $container->getDefinition($serviceId)->hasTag('messenger.bus');
    }
}
