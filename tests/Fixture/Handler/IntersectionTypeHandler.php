<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryAwareMessage;

final class IntersectionTypeHandler
{
    public function __invoke(CreateTaskCommand&RetryAwareMessage $message): void
    {
    }
}
