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
     * Relaxed from class-string<Event> to support attribute-only handlers.
     *
     * @param class-string          $event
     * @param non-empty-string|null $bus
     * @param int                   $priority      Handlers of the same event with a higher priority run first
     * @param non-empty-string|null $fromTransport Skip this handler for messages a worker received from another transport (synchronous dispatches still run it)
     */
    public function __construct(
        public readonly string $event,
        public readonly ?string $bus = null,
        public readonly int $priority = 0,
        public readonly ?string $fromTransport = null,
    ) {
    }
}
