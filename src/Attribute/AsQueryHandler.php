<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Attribute;

use Attribute;
use SomeWork\CqrsBundle\Contract\Query;

/**
 * Attribute to mark a service as a query handler.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AsQueryHandler
{
    /**
     * @param class-string<Query>   $query
     * @param non-empty-string|null $bus
     */
    public function __construct(
        public readonly string $query,
        public readonly ?string $bus = null,
    ) {
    }
}
