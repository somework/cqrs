<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use SomeWork\CqrsBundle\Outbox\OutboxMessage;

/**
 * Persists messages in a transactional outbox for reliable async dispatch.
 *
 * @internal Promote to @api in v3.1 after real-world validation
 */
interface OutboxStorage
{
    public function store(OutboxMessage $message): void;

    /**
     * @return list<OutboxMessage>
     */
    public function fetchUnpublished(int $limit): array;

    public function markPublished(string $id): void;
}
