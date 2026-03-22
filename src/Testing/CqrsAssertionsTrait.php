<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing;

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Constraint\LogicalNot;
use SomeWork\CqrsBundle\Support\MessageTypeLocator;
use SomeWork\CqrsBundle\Testing\Constraint\DispatchedMessage;

/**
 * PHPUnit trait providing CQRS-specific assertions and automatic state reset.
 *
 * Use in any TestCase class to get assertDispatched/assertNotDispatched helpers
 * and automatic MessageTypeLocator cache cleanup between tests.
 *
 * @api
 */
trait CqrsAssertionsTrait
{
    #[Before]
    protected function resetCqrsState(): void
    {
        MessageTypeLocator::reset();
    }

    /**
     * Assert that the given bus has dispatched a message of the expected class.
     *
     * @param class-string $messageClass
     */
    protected static function assertDispatched(
        RecordsBusDispatches $bus,
        string $messageClass,
        string $message = '',
    ): void {
        static::assertThat($bus, new DispatchedMessage($messageClass), $message);
    }

    /**
     * Assert that the given bus has NOT dispatched a message of the expected class.
     *
     * @param class-string $messageClass
     */
    protected static function assertNotDispatched(
        RecordsBusDispatches $bus,
        string $messageClass,
        string $message = '',
    ): void {
        static::assertThat($bus, new LogicalNot(new DispatchedMessage($messageClass)), $message);
    }
}
