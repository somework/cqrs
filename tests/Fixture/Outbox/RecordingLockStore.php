<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * Grants the lock and records the TTLs it was acquired and extended with.
 */
final class RecordingLockStore implements PersistingStoreInterface
{
    /** @var list<float> */
    public array $ttls = [];

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
        $this->ttls[] = $ttl;
    }
}
