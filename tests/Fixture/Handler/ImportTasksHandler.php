<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Contract\CommandHandler;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;
use SomeWork\CqrsBundle\Contract\EventBusInterface;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ImportTasksCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskImportedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

/**
 * Dispatches child messages while it is handled, for causation tracking.
 *
 * @implements CommandHandler<ImportTasksCommand>
 */
final class ImportTasksHandler implements CommandHandler, EnvelopeAware
{
    use EnvelopeAwareTrait;

    public function __construct(
        private readonly TaskRecorder $recorder,
        private readonly EventBusInterface $events,
    ) {
    }

    public function __invoke(ImportTasksCommand $command): mixed
    {
        $this->recorder->recordEnvelopeMessage(self::class, $this->getEnvelope());

        foreach ($command->taskIds as $taskId) {
            $this->events->dispatchSync(new TaskImportedEvent($taskId));
        }

        return null;
    }
}
