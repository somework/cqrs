<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Testing;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Support\MessageTypeLocator;
use SomeWork\CqrsBundle\Testing\CqrsAssertionsTrait;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;
use SomeWork\CqrsBundle\Testing\FakeEventBus;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskArchivedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\SpyServiceLocator;

#[CoversTrait(CqrsAssertionsTrait::class)]
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

    public function test_a_failure_names_the_bus_instead_of_exporting_it(): void
    {
        $bus = new FakeCommandBus();
        $bus->dispatch(new class implements Command {});

        try {
            self::assertDispatched($bus, CreateTaskCommand::class);
            self::fail('The assertion did not fail.');
        } catch (AssertionFailedError $failure) {
            self::assertStringStartsWith('Failed asserting that '.FakeCommandBus::class.' has dispatched a message of class "'.CreateTaskCommand::class.'".', $failure->getMessage());
            self::assertStringContainsString('Actually dispatched: ', $failure->getMessage());
            self::assertStringNotContainsString('records', $failure->getMessage());
        }
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

    public function test_reset_cqrs_state_clears_the_message_type_cache(): void
    {
        $locator = new SpyServiceLocator([\stdClass::class => static fn (): object => new \stdClass()]);

        MessageTypeLocator::match($locator, new \stdClass());
        MessageTypeLocator::match($locator, new \stdClass());
        self::assertSame(1, $locator->lookupCount(), 'The second lookup is served from the cache.');

        $this->resetCqrsState();

        MessageTypeLocator::match($locator, new \stdClass());
        self::assertSame(2, $locator->lookupCount());
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

    public function test_assert_stored_in_outbox_matches_only_outbox_dispatches(): void
    {
        $bus = new FakeCommandBus();
        $bus->dispatch(new CreateTaskCommand('1', 'a'), DispatchMode::OUTBOX);
        $bus->dispatch(new CreateTaskCommand('2', 'b'));

        self::assertStoredInOutbox($bus, CreateTaskCommand::class, static fn (CreateTaskCommand $command): bool => '1' === $command->id);
        self::assertNotStoredInOutbox($bus, CreateTaskCommand::class, static fn (CreateTaskCommand $command): bool => '2' === $command->id);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('with DispatchMode::OUTBOX');

        self::assertStoredInOutbox($bus, CreateTaskCommand::class, static fn (CreateTaskCommand $command): bool => '2' === $command->id);
    }

    public function test_a_default_dispatch_of_a_class_with_the_outbox_attribute_counts_as_stored(): void
    {
        // The bus stores it: #[Outbox] needs no configuration, unlike dispatch_modes.
        $bus = new FakeEventBus();
        $bus->dispatch(new TaskArchivedEvent('1'));
        $bus->dispatchSync(new TaskArchivedEvent('2'));

        self::assertStoredInOutbox($bus, TaskArchivedEvent::class, static fn (TaskArchivedEvent $event): bool => '1' === $event->taskId);
        self::assertNotStoredInOutbox($bus, TaskArchivedEvent::class, static fn (TaskArchivedEvent $event): bool => '2' === $event->taskId);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Actually dispatched: '.TaskArchivedEvent::class.' (DispatchMode::DEFAULT), '.TaskArchivedEvent::class.' (DispatchMode::SYNC)');

        self::assertStoredInOutbox($bus, TaskArchivedEvent::class, static fn (TaskArchivedEvent $event): bool => '3' === $event->taskId);
    }
}
