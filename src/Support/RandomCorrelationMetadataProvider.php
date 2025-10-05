<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\MessageMetadataProvider;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;

/**
 * Generates random correlation identifiers for dispatched messages.
 */
final class RandomCorrelationMetadataProvider implements MessageMetadataProvider
{
    public function getStamp(object $message, DispatchMode $mode): ?MessageMetadataStamp
    {
        return MessageMetadataStamp::createWithRandomCorrelationId();
    }
}
