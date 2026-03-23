<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Attribute;

use Attribute;

/**
 * Attribute to mark a service as an event handler.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AsEventHandler
{
    /**
     * Relaxed from class-string<Event> to support attribute-only handlers (DX-02).
     *
     * @param class-string          $event
     * @param non-empty-string|null $bus
     */
    public function __construct(
        public readonly string $event,
        public readonly ?string $bus = null,
    ) {
    }
}
