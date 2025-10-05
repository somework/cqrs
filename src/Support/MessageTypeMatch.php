<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

final class MessageTypeMatch
{
    public function __construct(
        public readonly string $type,
        public readonly mixed $service,
    ) {
    }
}
