<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Contract\MessageNamingStrategy;

/**
 * Uses the short class name as the human readable message name.
 */
final class ClassNameMessageNamingStrategy implements MessageNamingStrategy
{
    public function getName(string $messageClass): string
    {
        $parts = explode('\\', $messageClass);

        return end($parts) ?: $messageClass;
    }
}
