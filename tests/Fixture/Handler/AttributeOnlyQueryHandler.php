<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsQueryHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\PlainQuery;

/**
 * Handler using only the attribute, no QueryHandler interface.
 * Used to test attribute-only handler discovery (DX-02).
 */
#[AsQueryHandler(query: PlainQuery::class)]
final class AttributeOnlyQueryHandler
{
    public function __invoke(PlainQuery $query): string
    {
        return 'result';
    }
}
