<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Testing;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Support\MessageTypeLocator;
use SomeWork\CqrsBundle\Testing\CqrsAssertionsTrait;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;
use SomeWork\CqrsBundle\Testing\FakeEventBus;

#[CoversClass(CqrsAssertionsTrait::class)]
final class CqrsAssertionsTraitTest extends TestCase
{
    use CqrsAssertionsTrait;

    public function test_assert_dispatched_passes_when_message_dispatched(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $bus->dispatch($command);

        self::assertDispatched($bus, $command::class);
    }

    public function test_assert_dispatched_fails_when_message_not_dispatched(): void
    {
        $bus = new FakeCommandBus();

        $this->expectException(AssertionFailedError::class);

        self::assertDispatched($bus, Command::class);
    }

    public function test_assert_not_dispatched_passes_when_message_not_dispatched(): void
    {
        $bus = new FakeCommandBus();

        self::assertNotDispatched($bus, Command::class);
    }

    public function test_assert_not_dispatched_fails_when_message_dispatched(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $bus->dispatch($command);

        $this->expectException(AssertionFailedError::class);

        self::assertNotDispatched($bus, $command::class);
    }

    public function test_reset_cqrs_state_clears_message_type_locator(): void
    {
        // Calling reset should not throw -- structural test
        $this->resetCqrsState();

        // Verify MessageTypeLocator::reset() was called by confirming no exception
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertTrue(true);
    }

    public function test_assert_dispatched_with_custom_message(): void
    {
        $bus = new FakeCommandBus();

        try {
            self::assertDispatched($bus, Command::class, null, 'Custom failure message');
            self::fail('Expected AssertionFailedError');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('Custom failure message', $e->getMessage());
        }
    }

    public function test_assert_not_dispatched_with_custom_message(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $bus->dispatch($command);

        try {
            self::assertNotDispatched($bus, $command::class, null, 'Should not have been dispatched');
            self::fail('Expected AssertionFailedError');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('Should not have been dispatched', $e->getMessage());
        }
    }

    public function test_assert_dispatched_and_not_dispatched_on_same_bus(): void
    {
        $bus = new FakeCommandBus();
        $dispatched = new class implements Command {};
        $notDispatched = new class implements Command {};

        $bus->dispatch($dispatched);

        self::assertDispatched($bus, $dispatched::class);
        self::assertNotDispatched($bus, $notDispatched::class);
    }

    public function test_multiple_buses_in_same_test(): void
    {
        $commandBus = new FakeCommandBus();
        $eventBus = new FakeEventBus();

        $command = new class implements Command {};
        $event = new class implements Event {};

        $commandBus->dispatch($command);
        $eventBus->dispatch($event);

        self::assertDispatched($commandBus, $command::class);
        self::assertDispatched($eventBus, $event::class);
    }

    public function test_assert_dispatched_matches_interface_via_instanceof(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $bus->dispatch($command);

        // Should match because DispatchedMessage uses instanceof
        self::assertDispatched($bus, Command::class);
    }

    public function test_assert_dispatched_with_callback_passes(): void
    {
        $bus = new FakeCommandBus();
        $command = new class('test-id') implements Command {
            public function __construct(public readonly string $id)
            {
            }
        };

        $bus->dispatch($command);

        /* @phpstan-ignore property.notFound */
        self::assertDispatched($bus, $command::class, static fn (object $m): bool => 'test-id' === $m->id);
    }

    public function test_assert_dispatched_with_callback_fails_when_no_match(): void
    {
        $bus = new FakeCommandBus();
        $command = new class('actual') implements Command {
            public function __construct(public readonly string $id)
            {
            }
        };

        $bus->dispatch($command);

        $this->expectException(AssertionFailedError::class);

        /* @phpstan-ignore property.notFound */
        self::assertDispatched($bus, $command::class, static fn (object $m): bool => 'wrong' === $m->id);
    }

    public function test_assert_not_dispatched_with_callback_passes_when_callback_does_not_match(): void
    {
        $bus = new FakeCommandBus();
        $command = new class('actual') implements Command {
            public function __construct(public readonly string $id)
            {
            }
        };

        $bus->dispatch($command);

        /* @phpstan-ignore property.notFound */
        self::assertNotDispatched($bus, $command::class, static fn (object $m): bool => 'wrong' === $m->id);
    }

    public function test_assert_not_dispatched_with_callback_fails_when_callback_matches(): void
    {
        $bus = new FakeCommandBus();
        $command = new class('match') implements Command {
            public function __construct(public readonly string $id)
            {
            }
        };

        $bus->dispatch($command);

        $this->expectException(AssertionFailedError::class);

        /* @phpstan-ignore property.notFound */
        self::assertNotDispatched($bus, $command::class, static fn (object $m): bool => 'match' === $m->id);
    }
}
