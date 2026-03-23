<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\PlainEvent;

/**
 * Handler using only the attribute, no EventHandler interface.
 * Used to test attribute-only handler discovery (DX-02).
 */
#[AsEventHandler(event: PlainEvent::class)]
final class AttributeOnlyEventHandler
{
    public function __invoke(PlainEvent $event): void
    {
    }
}
