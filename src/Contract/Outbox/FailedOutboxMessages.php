<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract\Outbox;

use SomeWork\CqrsBundle\Outbox\FailedOutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;

/**
 * An outbox storage that lists the messages the relay gave up on and hands them back to it;
 * "somework:cqrs:outbox:failed" uses it.
 *
 * @api
 */
interface FailedOutboxMessages
{
    /**
     * The messages the relay gave up on, oldest failure first.
     *
     * @param list<string> $ids Only these messages; all when empty
     *
     * @return list<FailedOutboxMessage>
     */
    public function fetchFailed(int $limit, array $ids = []): array;

    /**
     * Hands messages the relay gave up on back to it, with a fresh attempt counter and no last error.
     *
     * @param list<string>                           $ids           The messages to requeue; all given-up messages when empty
     * @param string|null                            $transportName A transport to send them to instead of the stored one (e.g. after a renamed transport)
     * @param (\Closure(OutboxMessage): string)|null $sign          Stores a new signature, computed from the stored id, body and headers
     *
     * @return int The number of requeued messages
     */
    public function requeueFailed(array $ids = [], ?string $transportName = null, ?\Closure $sign = null): int;
}
