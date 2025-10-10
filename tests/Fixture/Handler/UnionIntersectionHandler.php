<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryableImportLegacyDataCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryAwareMessage;

final class UnionIntersectionHandler
{
    public function __invoke((CreateTaskCommand&RetryAwareMessage)|(RetryableImportLegacyDataCommand&RetryAwareMessage) $message): void
    {
    }
}
