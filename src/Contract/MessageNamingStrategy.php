<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

/**
 * Resolves display names for CQRS messages.
 */
interface MessageNamingStrategy
{
    /**
     * @param class-string $messageClass
     */
    public function getName(string $messageClass): string;
}
