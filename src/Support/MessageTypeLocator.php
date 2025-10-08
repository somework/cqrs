<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Container\ContainerInterface;
use WeakMap;

use function array_unique;
use function array_values;
use function class_implements;
use function get_parent_class;
use function implode;
use function iterator_to_array;
use function sort;

final class MessageTypeLocator
{
    /**
     * @var WeakMap<ContainerInterface, array<class-string, array<string, class-string>>>
     */
    private static WeakMap $matchCache;

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

        if (isset(self::$matchCache[$services][$messageClass][$ignoredSignature])) {
            $type = self::$matchCache[$services][$messageClass][$ignoredSignature];

            return new MessageTypeMatch($type, $services->get($type));
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

        $seenInterfaces = [];

        foreach ($classHierarchy as $type) {
            foreach (class_implements($type, false) as $interface) {
                foreach (self::interfaceHierarchy($interface, $seenInterfaces) as $candidate) {
                    if (isset($ignored[$candidate])) {
                        continue;
                    }

                    if ($services->has($candidate)) {
                        self::storeMatch($services, $messageClass, $ignoredSignature, $candidate);

                        return new MessageTypeMatch($candidate, $services->get($candidate));
                    }
                }
            }
        }

        return null;
    }

    private static function storeMatch(
        ContainerInterface $services,
        string $messageClass,
        string $ignoredSignature,
        string $type
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
     * @return iterable<class-string>
     */
    private static function classHierarchy(string $class): iterable
    {
        for ($type = $class; false !== $type; $type = get_parent_class($type)) {
            yield $type;
        }
    }

    /**
     * @param array<string, bool> $seen
     *
     * @return iterable<class-string>
     */
    private static function interfaceHierarchy(string $interface, array &$seen): iterable
    {
        if (isset($seen[$interface])) {
            return;
        }

        $seen[$interface] = true;

        yield $interface;

        foreach (class_implements($interface, false) as $parent) {
            yield from self::interfaceHierarchy($parent, $seen);
        }
    }
}
