<?php

declare(strict_types=1);

namespace App\Task\Event;

use SomeWork\CqrsBundle\Contract\Event;

/**
 * Event dispatched after a task is created.
 *
 * Events represent facts that already happened. Zero to many handlers may react.
 */
final class TaskCreated implements Event
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
    ) {
    }
}
