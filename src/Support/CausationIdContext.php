<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;

/**
 * Request-scoped stack of the metadata of the messages being handled, for causation tracking.
 *
 * The middleware pushes the metadata stamp of a message before its handlers run and pops it
 * after. The stamp deciders read current() to give a child message the correlation id of the
 * handled message and its message id as causation id.
 *
 * This is intentionally mutable (like Symfony's RequestStack). Tag with
 * kernel.reset in DI to clear between requests.
 *
 * @internal
 */
final class CausationIdContext
{
    /** @var list<MessageMetadataStamp> */
    private array $stack = [];

    public function push(MessageMetadataStamp $parent): void
    {
        $this->stack[] = $parent;
    }

    /**
     * Removes the most recent entry. Popping an empty stack is a no-op: the stack may
     * have been reset (kernel.reset) while a handler was running, and the middleware pops in a
     * "finally" block where an exception would hide the handler's own exception.
     */
    public function pop(): void
    {
        array_pop($this->stack);
    }

    public function current(): ?MessageMetadataStamp
    {
        if ([] === $this->stack) {
            return null;
        }

        return $this->stack[array_key_last($this->stack)];
    }

    public function reset(): void
    {
        $this->stack = [];
    }
}
