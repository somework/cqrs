<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use ReflectionClass;
use SomeWork\CqrsBundle\Attribute\Asynchronous;
use SomeWork\CqrsBundle\Attribute\Outbox;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Support\MessageTypeLocator;

/**
 * Resolves the effective dispatch mode for a message requested with DispatchMode::DEFAULT.
 *
 * Resolution order for commands and events: exact class entry in the configured map,
 * the #[Outbox] or #[Asynchronous] attribute on the message class, parent classes, interfaces
 * (most specific first), the per-type default. Queries are always synchronous.
 *
 * @internal
 */
final class DispatchModeDecider
{
    /**
     * @param array<class-string<Command>, DispatchMode> $commandMap
     * @param array<class-string<Event>, DispatchMode>   $eventMap
     */
    public function __construct(
        private readonly DispatchMode $commandDefault,
        private readonly DispatchMode $eventDefault,
        private readonly array $commandMap = [],
        private readonly array $eventMap = [],
    ) {
    }

    public static function syncDefaults(): self
    {
        return new self(DispatchMode::SYNC, DispatchMode::SYNC);
    }

    /** @var array<class-string<Command>, DispatchMode> */
    private array $commandModeCache = [];

    /** @var array<class-string<Event>, DispatchMode> */
    private array $eventModeCache = [];

    public function resolve(object $message, DispatchMode $requested): DispatchMode
    {
        if (DispatchMode::DEFAULT !== $requested) {
            return $requested;
        }

        if ($message instanceof Command) {
            $class = $message::class;

            return $this->commandModeCache[$class] ??= $this->resolveFor(
                $message,
                $this->commandMap,
                $this->commandDefault,
            );
        }

        if ($message instanceof Event) {
            $class = $message::class;

            return $this->eventModeCache[$class] ??= $this->resolveFor(
                $message,
                $this->eventMap,
                $this->eventDefault,
            );
        }

        return DispatchMode::SYNC;
    }

    /**
     * @param array<class-string, DispatchMode> $map
     */
    private function resolveFor(object $message, array $map, DispatchMode $default): DispatchMode
    {
        // An explicit configuration entry for the exact class wins over the class attribute...
        if (isset($map[$message::class])) {
            return $map[$message::class];
        }

        // ...and the #[Outbox] or #[Asynchronous] attribute wins over mappings of parents and interfaces.
        $reflection = new ReflectionClass($message);
        if ([] !== $reflection->getAttributes(Outbox::class)) {
            return DispatchMode::OUTBOX;
        }
        if ([] !== $reflection->getAttributes(Asynchronous::class)) {
            return DispatchMode::ASYNC;
        }

        // Parent classes, then interfaces, most specific first.
        foreach (MessageTypeLocator::typesOf($message::class) as $type) {
            if (isset($map[$type])) {
                return $map[$type];
            }
        }

        return $default;
    }

    public function reset(): void
    {
        $this->commandModeCache = [];
        $this->eventModeCache = [];
    }
}
