<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AsyncTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

#[AsCommandHandler(AsyncTaskCommand::class)]
final class AsyncTaskHandler
{
    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    public function __invoke(AsyncTaskCommand $command): void
    {
        $this->recorder->recordTask($command->id, 'async');
    }
}
