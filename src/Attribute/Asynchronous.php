<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Attribute;

use Attribute;

/**
 * Marks a message class for asynchronous dispatch.
 *
 * DispatchMode::DEFAULT resolves to async for the class (unless the dispatch_modes map has an
 * entry for exactly this class). On asynchronous dispatches the message goes to the attribute's
 * transport unless the transports configuration has an entry for exactly this class; a bare
 * attribute falls back to the "async" transport only when no transport is configured and
 * framework.messenger.routing does not route the message.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Asynchronous
{
    /**
     * @param non-empty-string|null $transport Transport name (defaults to 'async' when null)
     */
    public function __construct(
        public readonly ?string $transport = null,
    ) {
    }
}
