<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Contract\CommandHandler;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

#[AsCommandHandler(command: CreateTaskCommand::class)]
final class CreateTaskHandler implements CommandHandler, EnvelopeAware
{
    use EnvelopeAwareTrait;

    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    public function __invoke(CreateTaskCommand $command): mixed
    {
        $this->recorder->recordTask($command->id, $command->name);
        $this->recorder->recordEnvelopeMessage(self::class, $this->getEnvelope());

        return null;
    }
}
