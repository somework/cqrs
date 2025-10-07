<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

/**
 * @internal
 */
final class TransportMappingProvider
{
    /**
     * @param array{
     *     command: array{default: list<string>, map: array<class-string, list<string>>},
     *     command_async: array{default: list<string>, map: array<class-string, list<string>>},
     *     query: array{default: list<string>, map: array<class-string, list<string>>},
     *     event: array{default: list<string>, map: array<class-string, list<string>>},
     *     event_async: array{default: list<string>, map: array<class-string, list<string>>},
     * } $mapping
     */
    public function __construct(
        private readonly array $mapping,
    ) {
    }

    /**
     * @return array{
     *     default: list<string>,
     *     map: array<class-string, list<string>>,
     * }
     */
    public function forBus(string $bus): array
    {
        return $this->mapping[$bus] ?? ['default' => [], 'map' => []];
    }

    /**
     * @return array{
     *     command: array{default: list<string>, map: array<class-string, list<string>>},
     *     command_async: array{default: list<string>, map: array<class-string, list<string>>},
     *     query: array{default: list<string>, map: array<class-string, list<string>>},
     *     event: array{default: list<string>, map: array<class-string, list<string>>},
     *     event_async: array{default: list<string>, map: array<class-string, list<string>>},
     * }
     */
    public function all(): array
    {
        return $this->mapping;
    }
}
