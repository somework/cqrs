<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Handler\AbstractCommandHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

use function assert;

#[AsCommandHandler(command: CreateTaskCommand::class)]
final class CreateTaskHandler extends AbstractCommandHandler
{
    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    /**
     * @param CreateTaskCommand $command
     */
    protected function handle(Command $command): mixed
    {
        assert($command instanceof CreateTaskCommand);

        $this->recorder->recordTask($command->id, $command->name);
        $this->recorder->recordEnvelopeMessage(self::class, $this->getEnvelope());

        return null;
    }
}
