<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Testing\Constraint;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Constraint\LogicalNot;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Testing\Constraint\DispatchedMessage;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;
use SomeWork\CqrsBundle\Testing\FakeEventBus;
use SomeWork\CqrsBundle\Testing\FakeQueryBus;

#[CoversClass(DispatchedMessage::class)]
final class DispatchedMessageTest extends TestCase
{
    public function test_matches_when_expected_class_dispatched(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $bus->dispatch($command);

        $constraint = new DispatchedMessage($command::class);

        self::assertThat($bus, $constraint);
    }

    public function test_does_not_match_when_no_dispatches(): void
    {
        $bus = new FakeCommandBus();
        $constraint = new DispatchedMessage('App\NonExistent');

        self::assertThat($bus, new LogicalNot($constraint));
    }

    public function test_does_not_match_when_different_class_dispatched(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $bus->dispatch($command);

        $constraint = new DispatchedMessage('App\SomeOtherCommand');

        self::assertThat($bus, new LogicalNot($constraint));
    }

    public function test_to_string_contains_expected_class_name(): void
    {
        $constraint = new DispatchedMessage('App\MyCommand');

        self::assertStringContainsString('App\MyCommand', $constraint->toString());
    }

    public function test_works_with_logical_not_for_assert_not_dispatched(): void
    {
        $bus = new FakeEventBus();
        $event = new class implements Event {};

        $bus->dispatch($event);

        $constraint = new DispatchedMessage('App\UnrelatedEvent');

        self::assertThat($bus, new LogicalNot($constraint));
    }

    public function test_works_with_fake_query_bus(): void
    {
        $bus = new FakeQueryBus();
        $query = new class implements Query {};

        $bus->ask($query);

        $constraint = new DispatchedMessage($query::class);

        self::assertThat($bus, $constraint);
    }

    public function test_works_with_fake_event_bus(): void
    {
        $bus = new FakeEventBus();
        $event = new class implements Event {};

        $bus->dispatch($event);

        $constraint = new DispatchedMessage($event::class);

        self::assertThat($bus, $constraint);
    }

    public function test_matches_among_multiple_dispatched_messages(): void
    {
        $bus = new FakeCommandBus();
        $command1 = new class implements Command {};
        $command2 = new class implements Command {};

        $bus->dispatch($command1);
        $bus->dispatch($command2);

        $constraint = new DispatchedMessage($command2::class);

        self::assertThat($bus, $constraint);
    }

    public function test_does_not_match_non_records_bus_dispatches_value(): void
    {
        $constraint = new DispatchedMessage('App\SomeCommand');

        self::assertThat(new \stdClass(), new LogicalNot($constraint));
    }

    public function test_failure_description_when_no_messages_dispatched(): void
    {
        $bus = new FakeCommandBus();
        $constraint = new DispatchedMessage('App\SomeCommand');

        try {
            self::assertThat($bus, $constraint);
            self::fail('Expected assertion to fail');
        } catch (\PHPUnit\Framework\ExpectationFailedException $e) {
            self::assertStringContainsString('No messages were dispatched', $e->getMessage());
        }
    }

    public function test_failure_description_lists_actually_dispatched_classes(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $bus->dispatch($command);

        $constraint = new DispatchedMessage('App\NonExistentCommand');

        try {
            self::assertThat($bus, $constraint);
            self::fail('Expected assertion to fail');
        } catch (\PHPUnit\Framework\ExpectationFailedException $e) {
            self::assertStringContainsString('Actually dispatched:', $e->getMessage());
            self::assertStringContainsString($command::class, $e->getMessage());
        }
    }

    public function test_failure_description_deduplicates_classes(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $bus->dispatch($command);
        $bus->dispatch($command);

        $constraint = new DispatchedMessage('App\Other');

        try {
            self::assertThat($bus, $constraint);
            self::fail('Expected assertion to fail');
        } catch (\PHPUnit\Framework\ExpectationFailedException $e) {
            $message = $e->getMessage();
            $className = $command::class;
            // The "Actually dispatched:" line should list the class only once (deduplicated by array_unique)
            self::assertStringContainsString('Actually dispatched: '.$className, $message);
        }
    }

    public function test_matches_with_interface_based_instanceof(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $bus->dispatch($command);

        // DispatchedMessage uses instanceof so matching the interface should work
        $constraint = new DispatchedMessage(Command::class);

        self::assertThat($bus, $constraint);
    }

    public function test_does_not_match_after_bus_reset(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $bus->dispatch($command);
        $bus->reset();

        $constraint = new DispatchedMessage($command::class);

        self::assertThat($bus, new LogicalNot($constraint));
    }

    public function test_callback_matching_passes_when_property_matches(): void
    {
        $bus = new FakeCommandBus();
        $command = new class('expected-id') implements Command {
            public function __construct(public readonly string $id) {}
        };

        $bus->dispatch($command);

        $constraint = new DispatchedMessage($command::class, static fn (object $m): bool => $m->id === 'expected-id');

        self::assertThat($bus, $constraint);
    }

    public function test_callback_non_matching_fails(): void
    {
        $bus = new FakeCommandBus();
        $command = new class('actual-id') implements Command {
            public function __construct(public readonly string $id) {}
        };

        $bus->dispatch($command);

        $constraint = new DispatchedMessage($command::class, static fn (object $m): bool => $m->id === 'wrong-id');

        self::assertThat($bus, new LogicalNot($constraint));
    }

    public function test_null_callback_backward_compat(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $bus->dispatch($command);

        $constraint = new DispatchedMessage($command::class, null);

        self::assertThat($bus, $constraint);
    }

    public function test_callback_matches_second_message_in_iteration(): void
    {
        $bus = new FakeCommandBus();
        $command1 = new class('first') implements Command {
            public function __construct(public readonly string $id) {}
        };
        $command2 = new class('second') implements Command {
            public function __construct(public readonly string $id) {}
        };

        $bus->dispatch($command1);
        $bus->dispatch($command2);

        // Both are anonymous classes with different FQCNs, so we match on Command interface
        $constraint = new DispatchedMessage(Command::class, static fn (object $m): bool => $m->id === 'second');

        self::assertThat($bus, $constraint);
    }

    public function test_to_string_includes_matching_callback_when_provided(): void
    {
        $constraint = new DispatchedMessage('App\MyCommand', static fn (object $m): bool => true);

        self::assertStringContainsString('matching callback', $constraint->toString());
    }
}
