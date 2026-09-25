<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Symfony\Component\Messenger\Attribute\AsMessage;

use function class_exists;
use function class_implements;
use function class_parents;

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
        if (!class_exists(AsMessage::class)) {
            return false;
        }

        $parents = class_parents($messageClass);
        $interfaces = class_implements($messageClass);
        foreach ([$messageClass, ...(false === $parents ? [] : $parents), ...(false === $interfaces ? [] : $interfaces)] as $class) {
            foreach ((new \ReflectionClass($class))->getAttributes(AsMessage::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $transport = $attribute->newInstance()->transport;
                if (null !== $transport && [] !== $transport && '' !== $transport) {
                    return true;
                }
            }
        }

        return false;
    }
}
