<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

/**
 * Request-scoped stack of parent correlation IDs for causation tracking.
 *
 * When a handler dispatches a child message, the middleware pushes the parent's
 * correlation ID before handler execution and pops it after. The stamp decider
 * reads current() to inject causationId into the child's metadata stamp.
 *
 * This is intentionally mutable (like Symfony's RequestStack). Tag with
 * kernel.reset in DI to clear between requests.
 *
 * @internal
 */
final class CausationIdContext
{
    /** @var list<string> */
    private array $stack = [];

    public function push(string $correlationId): void
    {
        $this->stack[] = $correlationId;
    }

    /**
     * Removes the most recent correlation ID. Popping an empty stack is a no-op: the stack may
     * have been reset (kernel.reset) while a handler was running, and the middleware pops in a
     * "finally" block where an exception would hide the handler's own exception.
     */
    public function pop(): void
    {
        array_pop($this->stack);
    }

    public function current(): ?string
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
