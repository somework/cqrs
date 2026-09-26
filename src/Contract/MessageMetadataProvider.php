<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;

/**
 * Supplies metadata stamps applied when dispatching CQRS messages.
 *
 * Return a new stamp for each call (or null): the stamp carries the message id, so a stamp
 * shared between dispatches gives them the same id. While another message is handled, the
 * bundle replaces the stamp's correlation id with that of the handled message and sets the
 * causation id, unless the stamp already has a causation id.
 *
 * @api
 */
interface MessageMetadataProvider
{
    public function getStamp(object $message, DispatchMode $mode): ?MessageMetadataStamp;
}
