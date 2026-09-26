<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;

/**
 * Handles a CQRS command and a plain (non-CQRS) message through a union type.
 */
final class MixedUnionHandler
{
    public function __invoke(CreateTaskCommand|\stdClass $message): void
    {
    }
}
