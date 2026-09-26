<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArchiveTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

#[AsCommandHandler(command: ArchiveTaskCommand::class)]
final class ArchiveTaskHandler
{
    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    public function __invoke(ArchiveTaskCommand $command): mixed
    {
        $this->recorder->recordTask($command->taskId, 'archived');

        return null;
    }
}
