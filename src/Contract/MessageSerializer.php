<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use Symfony\Component\Messenger\Stamp\SerializerStamp;

/**
 * Supplies serializer stamps applied when dispatching CQRS messages.
 */
interface MessageSerializer
{
    public function getStamp(object $message, DispatchMode $mode): ?SerializerStamp;
}
