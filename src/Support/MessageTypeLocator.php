<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Container\ContainerInterface;
use WeakMap;

use function array_key_exists;
use function array_unique;
use function array_values;
use function class_implements;
use function get_parent_class;
use function implode;
use function iterator_to_array;
use function max;
use function sort;
use function usort;

/** @internal */
final class MessageTypeLocator
{
    /**
     * Matched type per locator, message class and ignored keys; null records "no match".
     * Service locators are immutable, so entries never go stale; they are released with the locator.
     *
     * @var WeakMap<ContainerInterface, array<class-string, array<string, class-string|null>>>
     */
    private static WeakMap $matchCache;

    /** @var array<string, int> */
    private static array $interfaceDepths = [];

    /**
     * @param list<string> $ignoredKeys
     */
    public static function match(ContainerInterface $services, object $message, array $ignoredKeys = []): ?MessageTypeMatch
    {
        if (!isset(self::$matchCache)) {
            self::$matchCache = new WeakMap();
        }

        $messageClass = $message::class;

        $signatureKeys = array_values(array_unique($ignoredKeys));
        $sortedSignature = $signatureKeys;
        sort($sortedSignature);
        $ignoredSignature = implode("\0", $sortedSignature);

        if (isset(self::$matchCache[$services][$messageClass]) && array_key_exists($ignoredSignature, self::$matchCache[$services][$messageClass])) {
            $type = self::$matchCache[$services][$messageClass][$ignoredSignature];

            return null === $type ? null : new MessageTypeMatch($type, $services->get($type));
        }

        $ignored = [];

        foreach ($signatureKeys as $key) {
            $ignored[$key] = true;
        }

        $classHierarchy = iterator_to_array(self::classHierarchy($messageClass), false);

        foreach ($classHierarchy as $type) {
            if (isset($ignored[$type])) {
                continue;
            }

            if ($services->has($type)) {
                self::storeMatch($services, $messageClass, $ignoredSignature, $type);

                return new MessageTypeMatch($type, $services->get($type));
            }
        }

        // Most specific interface first (same order as DispatchModeDecider), independent of the
        // order in which the class happens to declare its interfaces.
        foreach (self::interfacesByDepth($messageClass) as $interface) {
            if (isset($ignored[$interface])) {
                continue;
            }

            if ($services->has($interface)) {
                self::storeMatch($services, $messageClass, $ignoredSignature, $interface);

                return new MessageTypeMatch($interface, $services->get($interface));
            }
        }

        self::storeMatch($services, $messageClass, $ignoredSignature, null);

        return null;
    }

    /**
     * @internal Intended for test isolation
     */
    public static function reset(): void
    {
        self::$matchCache = new WeakMap();
    }

    private static function storeMatch(
        ContainerInterface $services,
        string $messageClass,
        string $ignoredSignature,
        ?string $type,
    ): void {
        if (!isset(self::$matchCache[$services])) {
            self::$matchCache[$services] = [];
        }

        if (!isset(self::$matchCache[$services][$messageClass])) {
            self::$matchCache[$services][$messageClass] = [];
        }

        self::$matchCache[$services][$messageClass][$ignoredSignature] = $type;
    }

    /**
     * @param class-string $class
     *
     * @return iterable<class-string>
     */
    private static function classHierarchy(string $class): iterable
    {
        for ($type = $class; false !== $type; $type = get_parent_class($type)) {
            yield $type;
        }
    }

    /**
     * @param class-string $class
     *
     * @return list<class-string>
     */
    private static function interfacesByDepth(string $class): array
    {
        $interfaces = array_values(self::interfacesOf($class));
        // usort() is stable: interfaces of equal depth keep their declaration order.
        usort($interfaces, static fn (string $a, string $b): int => self::interfaceDepth($b) <=> self::interfaceDepth($a));

        return $interfaces;
    }

    private static function interfaceDepth(string $interface): int
    {
        if (isset(self::$interfaceDepths[$interface])) {
            return self::$interfaceDepths[$interface];
        }

        $depth = 0;
        foreach (self::interfacesOf($interface) as $parent) {
            $depth = max($depth, 1 + self::interfaceDepth($parent));
        }

        return self::$interfaceDepths[$interface] = $depth;
    }

    /**
     * @return array<string, class-string>
     */
    private static function interfacesOf(string $type): array
    {
        $interfaces = class_implements($type);

        return false === $interfaces ? [] : $interfaces;
    }
}
