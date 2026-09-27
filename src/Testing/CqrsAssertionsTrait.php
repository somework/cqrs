<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing;

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Constraint\LogicalNot;
use SomeWork\CqrsBundle\Bus\DispatchMode;
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
    /**
     * @psalm-suppress InternalClass, InternalMethod
     */
    #[Before]
    protected function resetCqrsState(): void
    {
        MessageTypeLocator::reset();
    }

    /**
     * Assert that the given bus has dispatched a message of the expected class.
     *
     * @param class-string  $messageClass
     * @param callable|null $callback     Optional callback for property-level message verification
     */
    protected static function assertDispatched(
        RecordsBusDispatches $bus,
        string $messageClass,
        ?callable $callback = null,
        string $message = '',
    ): void {
        static::assertThat($bus, new DispatchedMessage($messageClass, $callback), $message);
    }

    /**
     * Assert that the given bus has NOT dispatched a message of the expected class.
     *
     * @param class-string  $messageClass
     * @param callable|null $callback     Optional callback for property-level message verification
     */
    protected static function assertNotDispatched(
        RecordsBusDispatches $bus,
        string $messageClass,
        ?callable $callback = null,
        string $message = '',
    ): void {
        static::assertThat($bus, new LogicalNot(new DispatchedMessage($messageClass, $callback)), $message);
    }

    /**
     * Assert that the given bus was asked to store a message of the expected class in the outbox
     * (dispatched with DispatchMode::OUTBOX, or with DispatchMode::DEFAULT when the class carries
     * #[Outbox]). A fake bus does not know the configuration: a DispatchMode::DEFAULT dispatch that
     * "dispatch_modes" sends to the outbox does not count.
     *
     * @param class-string  $messageClass
     * @param callable|null $callback     Optional callback for property-level message verification
     */
    protected static function assertStoredInOutbox(
        RecordsBusDispatches $bus,
        string $messageClass,
        ?callable $callback = null,
        string $message = '',
    ): void {
        static::assertThat($bus, new DispatchedMessage($messageClass, $callback, DispatchMode::OUTBOX), $message);
    }

    /**
     * Assert that the given bus was NOT asked to store a message of the expected class in the outbox.
     *
     * @param class-string  $messageClass
     * @param callable|null $callback     Optional callback for property-level message verification
     */
    protected static function assertNotStoredInOutbox(
        RecordsBusDispatches $bus,
        string $messageClass,
        ?callable $callback = null,
        string $message = '',
    ): void {
        static::assertThat($bus, new LogicalNot(new DispatchedMessage($messageClass, $callback, DispatchMode::OUTBOX)), $message);
    }
}
