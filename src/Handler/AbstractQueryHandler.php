<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Handler;

use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Contract\QueryHandler;

/**
 * Base class for query handlers that exposes a typed {@see fetch()} method.
 *
 * @template TQuery of Query
 * @template TResult
 */
abstract class AbstractQueryHandler implements QueryHandler, EnvelopeAware
{
    use EnvelopeAwareTrait;

    final public function __invoke(Query $query): mixed
    {
        return $this->fetch($query);
    }

    /**
     * @param TQuery $query
     *
     * @return TResult
     */
    abstract protected function fetch(Query $query): mixed;
}
