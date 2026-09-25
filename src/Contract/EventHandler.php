<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

/**
 * Marks a service as an event handler.
 *
 * Implement a public `__invoke()` method whose first parameter is type-hinted with the
 * concrete event class. The interface declares no method so implementations can use the
 * concrete event type (PHP forbids narrowing a declared parameter type). Alternatively
 * declare the event explicitly with {@see \SomeWork\CqrsBundle\Attribute\AsEventHandler}.
 *
 * Handlers SHOULD be stateless services and MUST NOT mutate the event.
 *
 * The templates document the handled message (and result) for readers and tools; PHPStan
 * cannot check them against __invoke(), which the interface does not declare.
 *
 * @template TEvent of Event = Event
 *
 * @api
 */
interface EventHandler
{
}
