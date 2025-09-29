<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Handler;

use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\EventHandler;

/**
 * Base class for event handlers that exposes a typed {@see on()} method.
 *
 * @template TEvent of Event
 */
abstract class AbstractEventHandler implements EventHandler, EnvelopeAware
{
    use EnvelopeAwareTrait;

    final public function __invoke(Event $event): void
    {
        $this->on($event);
    }

    /**
     * @param TEvent $event
     */
    abstract protected function on(Event $event): void;
}
