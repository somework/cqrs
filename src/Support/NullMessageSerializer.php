<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use Symfony\Component\Messenger\Stamp\SerializerStamp;

/**
 * Message serializer that never applies additional stamps.
 */
final class NullMessageSerializer implements MessageSerializer
{
    public function getStamp(object $message, DispatchMode $mode): ?SerializerStamp
    {
        return null;
    }
}
