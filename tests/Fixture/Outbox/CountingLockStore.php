<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * Grants the lock and counts how often it is extended after it was acquired.
 */
final class CountingLockStore implements PersistingStoreInterface
{
    public int $refreshes = -1;

    public function save(Key $key): void
    {
    }

    public function delete(Key $key): void
    {
    }

    public function exists(Key $key): bool
    {
        return true;
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        // The first extension happens while acquiring the lock.
        ++$this->refreshes;
    }
}
