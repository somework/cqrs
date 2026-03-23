<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing\Constraint;

use PHPUnit\Framework\Constraint\Constraint;
use SomeWork\CqrsBundle\Testing\RecordsBusDispatches;

use function array_map;
use function array_unique;
use function implode;

/**
 * PHPUnit constraint that asserts a FakeBus has dispatched a message of the expected class.
 *
 * @api
 */
final class DispatchedMessage extends Constraint
{
    private readonly ?\Closure $callback;

    public function __construct(
        private readonly string $expectedClass,
        ?callable $callback = null,
    ) {
        $this->callback = null !== $callback ? $callback(...) : null;
    }

    public function toString(): string
    {
        $description = 'has dispatched a message of class "'.$this->expectedClass.'"';

        if (null !== $this->callback) {
            $description .= ' matching callback';
        }

        return $description;
    }

    protected function matches(mixed $other): bool
    {
        if (!$other instanceof RecordsBusDispatches) {
            return false;
        }

        foreach ($other->getDispatched() as $record) {
            if (!$record['message'] instanceof $this->expectedClass) {
                continue;
            }

            if (null === $this->callback) {
                return true;
            }

            if (($this->callback)($record['message'])) {
                return true;
            }
        }

        return false;
    }

    protected function additionalFailureDescription(mixed $other): string
    {
        if (!$other instanceof RecordsBusDispatches) {
            return 'Value is not a RecordsBusDispatches instance.';
        }

        $dispatched = $other->getDispatched();

        if ([] === $dispatched) {
            return 'No messages were dispatched.';
        }

        $classes = array_unique(array_map(
            static fn (array $record): string => $record['message']::class,
            $dispatched,
        ));

        return 'Actually dispatched: '.implode(', ', $classes);
    }
}
