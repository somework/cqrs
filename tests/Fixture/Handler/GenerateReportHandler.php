<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Handler\AbstractCommandHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\GenerateReportCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

use function assert;

#[AsCommandHandler(command: GenerateReportCommand::class, bus: 'messenger.bus.commands_async')]
final class GenerateReportHandler extends AbstractCommandHandler
{
    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    /**
     * @param GenerateReportCommand $command
     */
    protected function handle(Command $command): mixed
    {
        assert($command instanceof GenerateReportCommand);

        $this->recorder->recordReport($command->reportId);

        return null;
    }
}
