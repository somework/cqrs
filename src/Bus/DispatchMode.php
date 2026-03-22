<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

/**
 * Defines how a message should be dispatched by a bus.
 *
 * @api
 */
enum DispatchMode: string
{
    case DEFAULT = 'default';
    case SYNC = 'sync';
    case ASYNC = 'async';
}
