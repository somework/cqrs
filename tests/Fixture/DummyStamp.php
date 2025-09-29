<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture;

use Symfony\Component\Messenger\Stamp\StampInterface;

final class DummyStamp implements StampInterface
{
    public function __construct(public readonly string $name)
    {
    }
}

