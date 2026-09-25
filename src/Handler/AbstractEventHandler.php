<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Handler;

use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\EventHandler;

/**
 * @api
 *
 * Base class for event handlers that also receive the Messenger envelope ({@see EnvelopeAware}).
 *
 * PHP does not let on() narrow its parameter: it stays Event, and TEvent only
 * type it for static analysis. As __invoke() is untyped, declare the handled message with
 * #[AsEventHandler(YourMessage::class)]. For new handlers, prefer implementing EventHandler with a
 * typed __invoke(YourMessage $message) (and EnvelopeAware when the envelope is needed).
 *
 * @template TEvent of Event
 *
 * @implements EventHandler<TEvent>
 */
abstract class AbstractEventHandler implements EventHandler, EnvelopeAware
{
    use EnvelopeAwareTrait;

    /** @param TEvent $event */
    final public function __invoke($event): void
    {
        $this->on($event);
    }

    /**
     * @param TEvent $event
     */
    abstract protected function on(Event $event): void;
}
