<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\AbstractMessengerBus;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\DispatchModeDecider;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Exception\OutboxNotConfiguredException;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\Stamp\OutboxStoredStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArchiveTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskArchivedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function array_map;
use function sprintf;

#[CoversClass(AbstractMessengerBus::class)]
#[CoversClass(CommandBus::class)]
#[CoversClass(EventBus::class)]
#[CoversClass(DispatchModeDecider::class)]
#[CoversClass(OutboxStoredStamp::class)]
#[CoversClass(OutboxNotConfiguredException::class)]
final class OutboxDispatchTest extends TestCase
{
    private InMemoryOutboxStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryOutboxStorage();
    }

    public function test_the_outbox_mode_stores_the_message_with_the_stamps_of_an_asynchronous_dispatch(): void
    {
        $bus = new CommandBus($this->neverCalled(), $this->neverCalled(), outbox: $this->writer());

        $envelope = $bus->dispatch(new CreateTaskCommand('1', 'a'), DispatchMode::OUTBOX, new TransportNamesStamp(['async', 'audit']), new DelayStamp(5));

        $stored = $envelope->last(OutboxStoredStamp::class);
        self::assertInstanceOf(OutboxStoredStamp::class, $stored);
        self::assertSame(['async', 'audit'], $stored->transportNames);
        self::assertSame($stored->ids, array_map(static fn ($row): string => $row->id, $this->storage->fetchUnpublished(10)));
        // The default pipeline defers asynchronous messages: a stored one is never deferred.
        self::assertNull($envelope->last(DispatchAfterCurrentBusStamp::class));
        foreach ($this->storage->fetchUnpublished(10) as $row) {
            $decoded = (new PhpSerializer())->decode(['body' => $row->body]);
            self::assertNull($decoded->last(DispatchAfterCurrentBusStamp::class));
            self::assertNotNull($decoded->last(DelayStamp::class), 'The caller stamps are stored.');
        }
    }

    public function test_the_default_mode_resolves_to_the_outbox_by_configuration_or_attribute(): void
    {
        $decider = new DispatchModeDecider(DispatchMode::SYNC, DispatchMode::SYNC, [ArchiveTaskCommand::class => DispatchMode::OUTBOX]);

        (new CommandBus($this->neverCalled(), null, $decider, outbox: $this->writer()))->dispatch(new ArchiveTaskCommand('1'));
        (new EventBus($this->neverCalled(), null, $decider, outbox: $this->writer()))->dispatch(new TaskArchivedEvent('1'));

        self::assertCount(2, $this->storage->fetchUnpublished(10));
    }

    public function test_the_explicit_modes_bypass_the_outbox(): void
    {
        $async = $this->createMock(MessageBusInterface::class);
        $async->expects(self::once())->method('dispatch')->willReturnCallback(static fn (object $message, array $stamps): Envelope => new Envelope($message, $stamps));
        $sync = $this->createMock(MessageBusInterface::class);
        $sync->expects(self::once())->method('dispatch')->willReturnCallback(static fn (object $message, array $stamps): Envelope => new Envelope($message, $stamps));
        $bus = new EventBus($sync, $async, new DispatchModeDecider(DispatchMode::SYNC, DispatchMode::SYNC), outbox: $this->writer());

        $bus->dispatchAsync(new TaskArchivedEvent('1'));
        $bus->dispatchSync(new TaskArchivedEvent('2'));

        self::assertSame([], $this->storage->fetchUnpublished(10));
    }

    public function test_the_outbox_mode_needs_the_outbox(): void
    {
        $this->expectException(OutboxNotConfiguredException::class);
        $this->expectExceptionMessage(sprintf('Message "%s" was dispatched on the event bus with DispatchMode::OUTBOX, but the transactional outbox is disabled.', TaskArchivedEvent::class));

        (new EventBus($this->neverCalled()))->dispatch(new TaskArchivedEvent('1'));
    }

    private function writer(): OutboxWriter
    {
        return new OutboxWriter($this->storage, new PhpSerializer());
    }

    private function neverCalled(): MessageBusInterface
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        return $bus;
    }
}
