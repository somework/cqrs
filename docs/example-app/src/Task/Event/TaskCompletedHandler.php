<?php

declare(strict_types=1);

namespace App\Task\Event;

use App\Task\Activity;
use Doctrine\ORM\EntityManagerInterface;
use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Contract\EventHandler;

use function sprintf;

/**
 * Reacts to task completion by recording an activity entry, like TaskCreatedHandler.
 */
#[AsEventHandler(event: TaskCompleted::class)]
final class TaskCompletedHandler implements EventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(TaskCompleted $event): void
    {
        $this->entityManager->persist(new Activity(sprintf('TaskCompleted handled: %s', $event->id)));
    }
}
