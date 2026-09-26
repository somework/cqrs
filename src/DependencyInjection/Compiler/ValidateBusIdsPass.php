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

/**
 * Fails the build when "somework_cqrs.buses" names a service that is not a Messenger bus.
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

        foreach (CqrsBusIds::BUS_KEYS as $key) {
            $parameter = 'somework_cqrs.bus.'.$key;
            $busId = $container->hasParameter($parameter) ? $container->getParameter($parameter) : null;

            if (!is_string($busId) || '' === $busId || $this->isBus($container, $busId)) {
                continue;
            }

            $known = array_keys($container->findTaggedServiceIds('messenger.bus'));
            sort($known);

            throw new InvalidConfigurationException(sprintf('"somework_cqrs.buses.%s" is "%s", which is not a Messenger bus. Known buses: %s. Declare it under "framework.messenger.buses".', $key, $busId, [] === $known ? 'none' : implode(', ', $known)));
        }
    }

    private function isBus(ContainerBuilder $container, string $busId): bool
    {
        $serviceId = CqrsBusIds::resolveAlias($container, $busId);

        return $container->hasDefinition($serviceId) && $container->getDefinition($serviceId)->hasTag('messenger.bus');
    }
}
