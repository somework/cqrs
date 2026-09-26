<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox\Relay;

/**
 * A storage that runs each dispatch of the relay as a unit of work of its own when the relay's
 * writes and the handlers it runs in its own process (a message without a transport, sync://)
 * would otherwise share one database transaction (a connection with auto-commit off).
 *
 * @internal
 */
interface RelayUnitOfWork
{
    /**
     * @template T
     *
     * @param \Closure(): T $dispatch
     *
     * @return T
     */
    public function dispatchInUnitOfWork(\Closure $dispatch): mixed;
}
