<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

/**
 * Defines how a message should be dispatched by a bus.
 *
 * OUTBOX stores the message in the transactional outbox, in the current database transaction,
 * instead of sending it; the relay sends it later, as an asynchronous dispatch would.
 *
 * @api
 */
enum DispatchMode: string
{
    case DEFAULT = 'default';
    case SYNC = 'sync';
    case ASYNC = 'async';
    case OUTBOX = 'outbox';
}
