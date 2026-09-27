<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract\Outbox;

use SomeWork\CqrsBundle\Outbox\OutboxStatus;

/**
 * An outbox storage that reports its backlog; the outbox check of "somework:cqrs:health" uses it.
 *
 * @api
 */
interface OutboxMonitoring
{
    /**
     * Only reads: it never changes the storage.
     */
    public function status(): OutboxStatus;
}
