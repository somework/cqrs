<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Attribute;

use Attribute;

/**
 * Attribute to mark a service as a query handler.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AsQueryHandler
{
    /**
     * Relaxed from class-string<Query> to support attribute-only handlers (DX-02).
     *
     * @param class-string          $query
     * @param non-empty-string|null $bus
     */
    public function __construct(
        public readonly string $query,
        public readonly ?string $bus = null,
    ) {
    }
}
