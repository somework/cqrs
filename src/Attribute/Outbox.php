<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Attribute;

use Attribute;

/**
 * Marks a message class for dispatch through the transactional outbox.
 *
 * DispatchMode::DEFAULT resolves to outbox for the class (unless the dispatch_modes map has an
 * entry for exactly this class): the bus stores the message in the outbox, inside the current
 * database transaction, and the relay sends it later. The transports are resolved as for
 * #[Asynchronous]: the attribute's transport unless the transports configuration has an entry
 * for exactly this class, then the configured transports, framework.messenger.routing, and the
 * "async" transport for a bare attribute.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Outbox
{
    /**
     * @param non-empty-string|null $transport Transport name; null uses the configured transports, then
     *                                         framework.messenger.routing, then the "async" transport
     */
    public function __construct(
        public readonly ?string $transport = null,
    ) {
    }
}
