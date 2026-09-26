<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Contract\CommandHandler;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;
use SomeWork\CqrsBundle\Tests\Fixture\Message\GenerateReportCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

#[AsCommandHandler(command: GenerateReportCommand::class, bus: 'messenger.bus.commands_async')]
final class GenerateReportHandler implements CommandHandler, EnvelopeAware
{
    use EnvelopeAwareTrait;

    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    public function __invoke(GenerateReportCommand $command): mixed
    {
        $this->recorder->recordReport($command->reportId);
        $this->recorder->recordEnvelopeMessage(self::class, $this->getEnvelope());

        return null;
    }
}
