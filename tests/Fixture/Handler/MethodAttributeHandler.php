<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;

final class MethodAttributeHandler
{
    public function handle(CreateTaskCommand $command): void
    {
        // no-op
    }
}
