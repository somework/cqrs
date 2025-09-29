<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Attribute;

use Attribute;
use SomeWork\CqrsBundle\Contract\Event;

/**
 * Attribute to mark a service as an event handler.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AsEventHandler
{
    /**
     * @param class-string<Event>   $event
     * @param non-empty-string|null $bus
     */
    public function __construct(
        public readonly string $event,
        public readonly ?string $bus = null,
    ) {
    }
}
