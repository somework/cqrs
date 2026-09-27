<?php

declare(strict_types=1);

namespace App\Task\Event;

use App\Task\Activity;
use Doctrine\ORM\EntityManagerInterface;
use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Contract\EventHandler;

use function sprintf;

/**
 * Reacts to task creation by recording an activity entry.
 *
 * It runs when the relay sends the stored event to the "sync" transport, in the relay's process;
 * the "doctrine_transaction" middleware of the event bus flushes the entry. In a real application,
 * this could send a notification, update another read model, or trigger a workflow.
 */
#[AsEventHandler(event: TaskCreated::class)]
final class TaskCreatedHandler implements EventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(TaskCreated $event): void
    {
        $this->entityManager->persist(new Activity(sprintf('TaskCreated handled: "%s" (%s)', $event->title, $event->id)));
    }
}
