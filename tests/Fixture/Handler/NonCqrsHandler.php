<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

final class NonCqrsHandler
{
    public function __invoke(\stdClass $message): void
    {
    }
}
