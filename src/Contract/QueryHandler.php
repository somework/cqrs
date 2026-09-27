<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

/**
 * Marks a service as a query handler.
 *
 * Implement a public `__invoke()` method whose first parameter is type-hinted with the
 * concrete query class and which returns the query result. The interface declares no
 * method so implementations can use the concrete query type (PHP forbids narrowing a
 * declared parameter type). Alternatively declare the query explicitly with
 * {@see \SomeWork\CqrsBundle\Attribute\AsQueryHandler}.
 *
 * Handlers SHOULD be stateless services and MUST NOT mutate the query.
 *
 * The templates document the handled message (and result) for readers and tools; PHPStan
 * cannot check them against __invoke(), which the interface does not declare.
 *
 * @template TQuery of Query = Query
 * @template TResult = mixed
 *
 * @api
 */
interface QueryHandler
{
}
