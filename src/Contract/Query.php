<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

/**
 * Marker interface for query messages.
 *
 * Queries are immutable read-only data transfer objects (DTOs) that describe
 * the data a caller wishes to retrieve: a final class with public readonly
 * properties, and no business logic.
 *
 * Declare the result type for static analysis with `@implements Query<ResultType>`:
 * QueryBusInterface::ask() then returns that type.
 *
 * @template-covariant TResult = mixed
 *
 * @psalm-immutable
 *
 * @api
 */
interface Query
{
}
