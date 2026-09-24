<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

use function array_keys;
use function array_unshift;
use function implode;
use function in_array;
use function is_string;
use function sprintf;

/**
 * Resolves the Messenger bus service ids used by the CQRS facades.
 *
 * Must only be used from compiler passes: aliases registered by other bundles
 * (e.g. FrameworkBundle's "messenger.default_bus") are not visible while extensions load.
 *
 * @internal
 */
final class CqrsBusIds
{
    public const BUS_KEYS = ['command', 'command_async', 'query', 'event', 'event_async'];

    /** Buses that fall back to the default bus when they are not configured. */
    private const DEFAULT_BUS_FALLBACK_KEYS = ['command', 'query', 'event'];

    /**
     * @return list<string> Unique, alias-resolved bus service ids
     */
    public static function resolve(ContainerBuilder $container): array
    {
        if (!$container->hasParameter('somework_cqrs.default_bus')) {
            return [];
        }

        $candidates = [];
        $usesDefaultBus = false;

        foreach (self::BUS_KEYS as $key) {
            $parameter = 'somework_cqrs.bus.'.$key;
            $busId = $container->hasParameter($parameter) ? $container->getParameter($parameter) : null;

            if (is_string($busId) && '' !== $busId) {
                $candidates[] = $busId;
            } elseif (in_array($key, self::DEFAULT_BUS_FALLBACK_KEYS, true)) {
                $usesDefaultBus = true;
            }
        }

        // The default bus is only a CQRS bus when a facade falls back to it; otherwise it may be an
        // unrelated bus (mailer, notifier) that must not get the bundle's middleware.
        $defaultBus = $container->getParameter('somework_cqrs.default_bus');
        if ($usesDefaultBus && is_string($defaultBus) && '' !== $defaultBus) {
            array_unshift($candidates, $defaultBus);
        }

        $resolved = [];
        foreach ($candidates as $busId) {
            $resolved[self::resolveAlias($container, $busId)] = true;
        }

        return array_keys($resolved);
    }

    public static function resolveAlias(ContainerBuilder $container, string $id): string
    {
        $visited = [];

        while ($container->hasAlias($id)) {
            if (isset($visited[$id])) {
                throw new InvalidArgumentException(sprintf('Circular alias detected while resolving bus "%s": %s.', $id, implode(' -> ', array_keys($visited))));
            }

            $visited[$id] = true;
            $id = (string) $container->getAlias($id);
        }

        return $id;
    }
}
