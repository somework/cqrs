<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing\Constraint;

use PHPUnit\Framework\Constraint\Constraint;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Testing\FakeOutbox;
use SomeWork\CqrsBundle\Testing\RecordedDispatch;
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

    /**
     * @param DispatchMode|null $mode Only dispatches requested with this mode (e.g. DispatchMode::OUTBOX; a
     *                                DispatchMode::DEFAULT dispatch of a class carrying #[Outbox] counts as OUTBOX)
     */
    public function __construct(
        private readonly string $expectedClass,
        ?callable $callback = null,
        private readonly ?DispatchMode $mode = null,
    ) {
        $this->callback = null !== $callback ? $callback(...) : null;
    }

    public function toString(): string
    {
        $description = null === $this->mode
            ? 'has dispatched a message of class "'.$this->expectedClass.'"'
            : 'has dispatched a message of class "'.$this->expectedClass.'" with DispatchMode::'.$this->mode->name;

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
            if (!$record->message instanceof $this->expectedClass || (null !== $this->mode && $this->mode !== self::modeOf($record))) {
                continue;
            }

            if (null === $this->callback) {
                return true;
            }

            if (($this->callback)($record->message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Names the bus instead of exporting it (with every record and configured result).
     */
    protected function failureDescription(mixed $other): string
    {
        if (!$other instanceof RecordsBusDispatches) {
            return parent::failureDescription($other);
        }

        return $other::class.' '.$this->toString();
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
            static fn (RecordedDispatch $record): string => null === $record->mode ? $record->message::class : $record->message::class.' (DispatchMode::'.$record->mode->name.')',
            $dispatched,
        ));

        return 'Actually dispatched: '.implode(', ', $classes);
    }

    /**
     * A DEFAULT dispatch of a class carrying #[Outbox] goes to the outbox ("dispatch_modes" is not
     * known here).
     *
     * @param RecordedDispatch<object> $record
     */
    private static function modeOf(RecordedDispatch $record): ?DispatchMode
    {
        return FakeOutbox::isOutboxDispatch($record->message, $record->mode) ? DispatchMode::OUTBOX : $record->mode;
    }
}
