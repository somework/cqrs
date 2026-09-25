<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Handler;

use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Contract\QueryHandler;

/**
 * @api
 *
 * Base class for query handlers that also receive the Messenger envelope ({@see EnvelopeAware}).
 *
 * PHP does not let fetch() narrow its parameter: it stays Query, and TQuery only
 * type it for static analysis. As __invoke() is untyped, declare the handled message with
 * #[AsQueryHandler(YourMessage::class)]. For new handlers, prefer implementing QueryHandler with a
 * typed __invoke(YourMessage $message) (and EnvelopeAware when the envelope is needed).
 *
 * @template TQuery of Query
 * @template TResult
 *
 * @implements QueryHandler<TQuery, TResult>
 */
abstract class AbstractQueryHandler implements QueryHandler, EnvelopeAware
{
    use EnvelopeAwareTrait;

    /** @param TQuery $query */
    final public function __invoke($query): mixed
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
