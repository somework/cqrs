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

    public function pop(): void
    {
        if ([] === $this->stack) {
            throw new \LogicException('Cannot pop from empty causation ID stack.');
        }

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
