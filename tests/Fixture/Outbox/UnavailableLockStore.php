<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * Grants the lock, then goes away (e.g. Redis restarts): every later call fails.
 */
final class UnavailableLockStore implements PersistingStoreInterface
{
    private int $extensions = 0;

    public function save(Key $key): void
    {
    }

    public function delete(Key $key): void
    {
        throw new \RuntimeException('Connection lost');
    }

    public function exists(Key $key): bool
    {
        if ($this->extensions > 1) {
            throw new \RuntimeException('Connection lost');
        }

        return true;
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        // The first extension happens while acquiring the lock.
        if (++$this->extensions > 1) {
            throw new \RuntimeException('Connection lost');
        }
    }
}
