<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing;

use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Contract\QueryBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function array_key_exists;
use function class_exists;
use function sprintf;

/**
 * Test double for QueryBus that records all asks and returns configurable results.
 *
 * @api
 */
final class FakeQueryBus implements QueryBusInterface, RecordsBusDispatches
{
    /** @var list<RecordedDispatch<Query>> */
    private array $dispatched = [];

    private ?\Throwable $failure = null;

    /** @var array<class-string, \Throwable> */
    private array $failureMap = [];

    private mixed $defaultResult = null;

    /** @var array<class-string, mixed> */
    private array $resultMap = [];

    /**
     * @template TResult
     *
     * @param Query<TResult> $query
     *
     * @return TResult
     */
    public function ask(Query $query, StampInterface ...$stamps): mixed
    {
        $this->dispatched[] = new RecordedDispatch($query, null, array_values($stamps));

        $failure = $this->failureMap[$query::class] ?? $this->failure;
        if (null !== $failure) {
            throw $failure;
        }

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
     * Configures the result {@see ask()} returns for queries of exactly the given class.
     *
     * @template TResult
     *
     * @param class-string<Query<TResult>> $queryClass
     * @param TResult                      $result
     */
    public function willReturnFor(string $queryClass, mixed $result): void
    {
        self::assertConcrete($queryClass);
        $this->resultMap[$queryClass] = $result;
    }

    /**
     * Makes {@see ask()} throw, for every query or only for the given class, as a failing
     * handler would (the query is recorded first).
     *
     * @param class-string<Query>|null $queryClass
     */
    public function willThrow(\Throwable $exception, ?string $queryClass = null): void
    {
        if (null === $queryClass) {
            $this->failure = $exception;
        } else {
            self::assertConcrete($queryClass);
            $this->failureMap[$queryClass] = $exception;
        }
    }

    /**
     * @return list<RecordedDispatch<Query>>
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
        $this->failure = null;
        $this->failureMap = [];
    }

    /**
     * The fake matches messages by their exact class: an interface or an abstract class would never match.
     */
    private static function assertConcrete(string $class): void
    {
        if (!class_exists($class) || (new \ReflectionClass($class))->isAbstract()) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a concrete message class: the fake bus matches messages by their exact class.', $class));
        }
    }
}
