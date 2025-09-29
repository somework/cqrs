<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

/**
 * Defines how a message should be dispatched by a bus.
 */
enum DispatchMode: string
{
    case SYNC = 'sync';
    case ASYNC = 'async';
}
