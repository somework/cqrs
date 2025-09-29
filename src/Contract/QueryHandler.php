<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

/**
 * @template TQuery of Query
 * @template TResult
 */
interface QueryHandler
{
    /**
     * Execute the query and return the result.
     *
     * Implementations SHOULD be stateless services. They MUST NOT mutate the
     * provided query instance.
     *
     * @param TQuery $query
     *
     * @return TResult
     */
    public function __invoke(Query $query): mixed;
}
