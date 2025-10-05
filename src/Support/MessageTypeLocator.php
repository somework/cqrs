<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Container\ContainerInterface;

use function class_implements;
use function get_parent_class;
use function iterator_to_array;

final class MessageTypeLocator
{
    /**
     * @param list<string> $ignoredKeys
     */
    public static function match(ContainerInterface $services, object $message, array $ignoredKeys = []): ?MessageTypeMatch
    {
        $ignored = [];

        foreach ($ignoredKeys as $key) {
            $ignored[$key] = true;
        }

        $messageClass = $message::class;
        $classHierarchy = iterator_to_array(self::classHierarchy($messageClass), false);

        foreach ($classHierarchy as $type) {
            if (isset($ignored[$type])) {
                continue;
            }

            if ($services->has($type)) {
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
                        return new MessageTypeMatch($candidate, $services->get($candidate));
                    }
                }
            }
        }

        return null;
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
