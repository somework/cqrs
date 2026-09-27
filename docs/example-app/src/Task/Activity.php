<?php

declare(strict_types=1);

namespace App\Task;

use Doctrine\ORM\Mapping as ORM;

/**
 * What the event handlers did: a read model they write when the relay hands them the events.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_activity')]
class Activity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 255)]
        private string $description,
    ) {
    }

    public function description(): string
    {
        return $this->description;
    }
}
