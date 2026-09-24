<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * Grants the lock but cannot extend it later, as when another process took it over after expiry.
 */
final class LosingLockStore implements PersistingStoreInterface
{
    private int $extensions = 0;

    public function save(Key $key): void
    {
    }

    public function delete(Key $key): void
    {
    }

    public function exists(Key $key): bool
    {
        return $this->extensions <= 1;
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        // The first extension happens while acquiring the lock.
        if (++$this->extensions > 1) {
            throw new LockConflictedException('The lock is held by another process.');
        }
    }
}
