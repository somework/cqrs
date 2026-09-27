<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Symfony\Component\Messenger\Attribute\AsMessage;

use function class_implements;
use function class_parents;
use function in_array;
use function is_string;

/**
 * Whether Messenger routes a message by #[AsMessage(transport: ...)] on its class, a parent class
 * or an interface (the way its SendersLocator reads the attribute when the routing has no entry).
 *
 * @internal
 */
final class AsMessageRouting
{
    /**
     * @param class-string $messageClass
     */
    public static function hasTransport(string $messageClass): bool
    {
        return [] !== self::transports($messageClass);
    }

    /**
     * The transports of every #[AsMessage] on the class, its parent classes and interfaces, merged
     * the way SendersLocator merges them.
     *
     * @param class-string $messageClass
     *
     * @return list<string>
     */
    public static function transports(string $messageClass): array
    {
        $transports = [];
        $parents = class_parents($messageClass);
        $interfaces = class_implements($messageClass);
        foreach ([$messageClass, ...(false === $parents ? [] : $parents), ...(false === $interfaces ? [] : $interfaces)] as $class) {
            foreach ((new \ReflectionClass($class))->getAttributes(AsMessage::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                foreach ((array) $attribute->newInstance()->transport as $transport) {
                    if (is_string($transport) && '' !== $transport && !in_array($transport, $transports, true)) {
                        $transports[] = $transport;
                    }
                }
            }
        }

        return $transports;
    }
}
