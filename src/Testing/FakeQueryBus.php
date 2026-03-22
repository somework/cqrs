<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing;

use SomeWork\CqrsBundle\Contract\Query;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Test double for QueryBus that records all asks and returns configurable results.
 *
 * @api
 */
final class FakeQueryBus implements RecordsBusDispatches
{
    /** @var list<array{message: Query, stamps: list<StampInterface>}> */
    private array $dispatched = [];

    private mixed $defaultResult = null;

    /** @var array<class-string, mixed> */
    private array $resultMap = [];

    public function ask(Query $query, StampInterface ...$stamps): mixed
    {
        $this->dispatched[] = [
            'message' => $query,
            'stamps' => array_values($stamps),
        ];

        return $this->resultMap[$query::class] ?? $this->defaultResult;
    }

    public function willReturn(mixed $result): void
    {
        $this->defaultResult = $result;
    }

    /**
     * @param class-string $queryClass
     */
    public function willReturnFor(string $queryClass, mixed $result): void
    {
        $this->resultMap[$queryClass] = $result;
    }

    public function getDispatched(): array
    {
        return $this->dispatched;
    }

    public function reset(): void
    {
        $this->dispatched = [];
        $this->defaultResult = null;
        $this->resultMap = [];
    }
}
