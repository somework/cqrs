<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;

/**
 * Event handler with a priority that only runs for messages received from the "audit" transport.
 */
#[AsEventHandler(TaskCreatedEvent::class, priority: 10, fromTransport: 'audit')]
final class TransportBoundHandlers
{
    public function __invoke(TaskCreatedEvent $event): void
    {
    }
}
