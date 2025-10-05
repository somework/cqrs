<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;

/**
 * Supplies metadata stamps applied when dispatching CQRS messages.
 */
interface MessageMetadataProvider
{
    public function getStamp(object $message, DispatchMode $mode): ?MessageMetadataStamp;
}
