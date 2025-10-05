<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;

/**
 * Resolves the effective dispatch mode for a message based on configuration.
 */
final class DispatchModeDecider
{
    /**
     * @param array<class-string<Command>, DispatchMode> $commandMap
     * @param array<class-string<Event>, DispatchMode> $eventMap
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

    public function resolve(object $message, DispatchMode $requested): DispatchMode
    {
        if (DispatchMode::DEFAULT !== $requested) {
            return $requested;
        }

        if ($message instanceof Command) {
            return $this->commandMap[$message::class] ?? $this->commandDefault;
        }

        if ($message instanceof Event) {
            return $this->eventMap[$message::class] ?? $this->eventDefault;
        }

        return DispatchMode::SYNC;
    }
}
