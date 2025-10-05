<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use ReflectionClass;
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

    /** @var array<class-string, int> */
    private array $interfaceDepthCache = [];

    public function resolve(object $message, DispatchMode $requested): DispatchMode
    {
        if (DispatchMode::DEFAULT !== $requested) {
            return $requested;
        }

        if ($message instanceof Command) {
            return $this->resolveFor($message, $this->commandMap, $this->commandDefault);
        }

        if ($message instanceof Event) {
            return $this->resolveFor($message, $this->eventMap, $this->eventDefault);
        }

        return DispatchMode::SYNC;
    }

    /**
     * @param array<class-string, DispatchMode> $map
     */
    private function resolveFor(object $message, array $map, DispatchMode $default): DispatchMode
    {
        foreach ($this->getClassHierarchy($message) as $class) {
            if (isset($map[$class])) {
                return $map[$class];
            }
        }

        foreach ($this->getInterfaceHierarchy($message) as $interface) {
            if (isset($map[$interface])) {
                return $map[$interface];
            }
        }

        return $default;
    }

    /**
     * @return list<class-string>
     */
    private function getClassHierarchy(object $message): array
    {
        $classes = [$message::class];
        $parents = class_parents($message);

        if (false !== $parents) {
            $classes = [...$classes, ...array_values($parents)];
        }

        return $classes;
    }

    /**
     * @return list<class-string>
     */
    private function getInterfaceHierarchy(object $message): array
    {
        $interfaces = class_implements($message);

        if (false === $interfaces || [] === $interfaces) {
            return [];
        }

        $interfaces = array_values($interfaces);
        usort(
            $interfaces,
            fn (string $a, string $b): int => $this->getInterfaceDepth($b) <=> $this->getInterfaceDepth($a)
        );

        return $interfaces;
    }

    private function getInterfaceDepth(string $interface): int
    {
        if (isset($this->interfaceDepthCache[$interface])) {
            return $this->interfaceDepthCache[$interface];
        }

        if (!interface_exists($interface)) {
            return $this->interfaceDepthCache[$interface] = 0;
        }

        $reflection = new ReflectionClass($interface);
        $parents = $reflection->getInterfaceNames();

        if ([] === $parents) {
            return $this->interfaceDepthCache[$interface] = 0;
        }

        $depth = 1;
        foreach ($parents as $parent) {
            $depth = max($depth, 1 + $this->getInterfaceDepth($parent));
        }

        $this->interfaceDepthCache[$interface] = $depth;

        return $depth;
    }
}
