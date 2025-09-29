<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Retry policy that applies no additional stamps.
 */
final class NullRetryPolicy implements RetryPolicy
{
    /**
     * @return list<StampInterface>
     */
    public function getStamps(object $message, DispatchMode $mode): array
    {
        return [];
    }
}
