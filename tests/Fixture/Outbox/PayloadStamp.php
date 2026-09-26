<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * A stamp with an untyped property, which can hold any object.
 */
final class PayloadStamp implements StampInterface
{
    public function __construct(public readonly mixed $payload)
    {
    }
}
