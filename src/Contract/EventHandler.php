<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

/**
 * @template TEvent of Event
 */
interface EventHandler
{
    /**
     * Handle the given event.
     *
     * Implementations SHOULD be stateless services. They MUST NOT mutate the
     * provided event instance.
     *
     * @param TEvent $event
     */
    public function __invoke(Event $event): void;
}
