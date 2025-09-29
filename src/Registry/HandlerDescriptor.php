<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Registry;

/**
 * @internal
 */
final class HandlerDescriptor
{
    public function __construct(
        public readonly string $type,
        public readonly string $messageClass,
        public readonly string $handlerClass,
        public readonly string $serviceId,
        public readonly ?string $bus,
    ) {
    }
}
