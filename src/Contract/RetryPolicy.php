<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Provides messenger stamps that control retry behaviour when dispatching messages.
 */
interface RetryPolicy
{
    /**
     * @return list<StampInterface>
     */
    public function getStamps(object $message, DispatchMode $mode): array;
}
