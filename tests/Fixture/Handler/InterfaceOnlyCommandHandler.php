<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Contract\CommandHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\GenerateReportCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

/**
 * Discovered through the marker interface alone: the command is inferred from the type-hint.
 *
 * @implements CommandHandler<GenerateReportCommand>
 */
final class InterfaceOnlyCommandHandler implements CommandHandler
{
    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    public function __invoke(GenerateReportCommand $command): string
    {
        $this->recorder->recordReport($command->reportId);

        return 'report:'.$command->reportId;
    }
}
