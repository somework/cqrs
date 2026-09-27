<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Service;

/**
 * Counts the flushes of the entity manager (a Doctrine "preFlush" listener): DoctrineBundle's
 * "doctrine_transaction" middleware flushes it after the handlers ran.
 */
final class FlushCounter
{
    public int $flushes = 0;

    public function preFlush(): void
    {
        ++$this->flushes;
    }
}
