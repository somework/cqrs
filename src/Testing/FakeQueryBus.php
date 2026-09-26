<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing;

use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Contract\QueryBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function array_key_exists;

/**
 * Test double for QueryBus that records all asks and returns configurable results.
 *
 * @api
 */
final class FakeQueryBus implements QueryBusInterface, RecordsBusDispatches
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

        if (array_key_exists($query::class, $this->resultMap)) {
            return $this->resultMap[$query::class];
        }

        return $this->defaultResult;
    }

    /**
     * Configures the result returned for queries without a class-specific result.
     */
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

    /**
     * @return list<array{message: Query, stamps: list<StampInterface>}>
     */
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
