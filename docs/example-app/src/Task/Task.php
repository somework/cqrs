<?php

declare(strict_types=1);

namespace App\Task;

use App\Task\Event\TaskCompleted;
use App\Task\Event\TaskCreated;
use Doctrine\ORM\Mapping as ORM;
use SomeWork\CqrsBundle\Contract\RecordsEvents;
use SomeWork\CqrsBundle\Contract\RecordsEventsTrait;

/**
 * A task: the entity records a domain event for each change. With "doctrine_events" enabled, the
 * events are stored in the transactional outbox when the entity manager flushes, in the same
 * transaction as the change; no handler dispatches them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task')]
class Task implements RecordsEvents
{
    use RecordsEventsTrait;

    #[ORM\Column]
    private bool $completed = false;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        private string $id,
        #[ORM\Column(length: 255)]
        private string $title,
    ) {
    }

    public static function create(string $id, string $title): self
    {
        $task = new self($id, $title);
        $task->recordThat(new TaskCreated($id, $title));

        return $task;
    }

    public function complete(): void
    {
        if ($this->completed) {
            return;
        }

        $this->completed = true;
        $this->recordThat(new TaskCompleted($this->id));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }
}
