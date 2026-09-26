<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\AbstractMessengerBus;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\DispatchModeDecider;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Contract\Outbox\TransactionalOutbox;
use SomeWork\CqrsBundle\Contract\StampDecider;
use SomeWork\CqrsBundle\Exception\OutboxNotConfiguredException;
use SomeWork\CqrsBundle\Exception\OutboxRequiresTransactionException;
use SomeWork\CqrsBundle\Messenger\OutboxPrepareMiddleware;
use SomeWork\CqrsBundle\Messenger\OutboxStoreMiddleware;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\Stamp\OutboxStoredStamp;
use SomeWork\CqrsBundle\Stamp\RelayedFromOutboxStamp;
use SomeWork\CqrsBundle\Stamp\StoreInOutboxStamp;
use SomeWork\CqrsBundle\Support\StampsDecider;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArchiveTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\StockReservedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskArchivedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\AddDefaultStampsMiddleware;
use Symfony\Component\Messenger\Middleware\DeduplicateMiddleware;
use Symfony\Component\Messenger\Middleware\DispatchAfterCurrentBusMiddleware;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
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
#[CoversClass(OutboxStoreMiddleware::class)]
#[CoversClass(OutboxPrepareMiddleware::class)]
#[CoversClass(OutboxStoredStamp::class)]
#[CoversClass(StoreInOutboxStamp::class)]
#[CoversClass(RelayedFromOutboxStamp::class)]
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
        $bus = new CommandBus($this->neverCalled(), $this->storingBus(), outbox: $this->writer());

        $envelope = $bus->dispatch(new CreateTaskCommand('1', 'a'), DispatchMode::OUTBOX, new TransportNamesStamp(['async', 'audit']), new DelayStamp(5));

        $stored = $envelope->last(OutboxStoredStamp::class);
        self::assertInstanceOf(OutboxStoredStamp::class, $stored);
        self::assertSame(['async', 'audit'], $stored->transportNames);
        self::assertSame($stored->ids, array_map(static fn ($row): string => $row->id, $this->storage->fetchUnpublished(10)));
        // The default pipeline defers asynchronous messages: a stored one is never deferred.
        self::assertNull($envelope->last(DispatchAfterCurrentBusStamp::class));
        self::assertNull($envelope->last(StoreInOutboxStamp::class));
        foreach ($this->storage->fetchUnpublished(10) as $row) {
            $decoded = (new PhpSerializer())->decode(['body' => $row->body]);
            self::assertNull($decoded->last(DispatchAfterCurrentBusStamp::class));
            self::assertNotNull($decoded->last(DelayStamp::class), 'The caller stamps are stored.');
        }
    }

    public function test_the_default_mode_resolves_to_the_outbox_by_configuration_or_attribute(): void
    {
        $decider = new DispatchModeDecider(DispatchMode::SYNC, DispatchMode::SYNC, [ArchiveTaskCommand::class => DispatchMode::OUTBOX]);

        (new CommandBus($this->storingBus(), null, $decider, outbox: $this->writer()))->dispatch(new ArchiveTaskCommand('1'));
        (new EventBus($this->storingBus(), null, $decider, outbox: $this->writer()))->dispatch(new TaskArchivedEvent('1'));

        self::assertCount(2, $this->storage->fetchUnpublished(10));
    }

    public function test_the_message_goes_through_the_middleware_of_the_async_bus_before_it_is_stored(): void
    {
        $stamping = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return $stack->next()->handle($envelope->with(new DelayStamp(42)), $stack);
            }
        };
        $bus = new CommandBus($this->neverCalled(), $this->storingBus($stamping), outbox: $this->writer());

        $envelope = $bus->dispatch(new CreateTaskCommand('1', 'a'), DispatchMode::OUTBOX);

        self::assertSame(42, $envelope->last(DelayStamp::class)?->getDelay());
        $row = $this->storage->fetchUnpublished(10)[0];
        self::assertSame(42, (new PhpSerializer())->decode(['body' => $row->body])->last(DelayStamp::class)?->getDelay(), 'The stamps of the middleware are stored.');
    }

    public function test_a_middleware_that_rejects_the_message_prevents_the_store(): void
    {
        $validation = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new \DomainException('invalid');
            }
        };
        $bus = new EventBus($this->storingBus($validation), outbox: $this->writer());

        try {
            $bus->dispatch(new TaskArchivedEvent('1'), DispatchMode::OUTBOX);
            self::fail('The middleware should have rejected the message.');
        } catch (\DomainException) {
        }

        self::assertSame([], $this->storage->fetchUnpublished(10));
    }

    #[RequiresMethod(DeduplicateStamp::class, '__construct')]
    public function test_the_deduplication_stamp_skips_the_bus_middleware_and_is_stored(): void
    {
        $spy = new class implements MiddlewareInterface {
            /** @var list<bool> Whether the envelope carried a DeduplicateStamp, per dispatch */
            public array $seen = [];

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->seen[] = null !== $envelope->last(DeduplicateStamp::class);

                return $stack->next()->handle($envelope, $stack);
            }
        };
        $bus = new EventBus($this->storingBus($spy), outbox: $this->writer());

        $envelope = $bus->dispatch(new TaskArchivedEvent('1'), DispatchMode::OUTBOX, new TransportNamesStamp(['async']), new DeduplicateStamp('key'));

        // Messenger's deduplication middleware would take the lock now and drop the relay's dispatch.
        self::assertSame([false], $spy->seen);
        self::assertSame('key@async', (string) (new PhpSerializer())->decode(['body' => $this->storage->fetchUnpublished(10)[0]->body])->last(DeduplicateStamp::class)?->getKey());
        self::assertNotNull($envelope->last(DeduplicateStamp::class));
    }

    #[RequiresMethod(AddDefaultStampsMiddleware::class, 'handle')]
    public function test_default_stamps_of_the_message_neither_lock_nor_defer_the_store(): void
    {
        // Messenger's default stamps come after the bus hid its own: a lock taken now would make the
        // next dispatch of the key a "duplicate" that is never stored, and a deferral would wait for
        // the current handler.
        $locks = new LockFactory(new InMemoryStore());
        $handling = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new \LogicException('The message should have been stored, not handled.');
            }
        };
        $bus = new EventBus(new MessageBus([
            new AddDefaultStampsMiddleware(),
            new OutboxPrepareMiddleware(),
            new DispatchAfterCurrentBusMiddleware(),
            new DeduplicateMiddleware($locks),
            new OutboxStoreMiddleware($this->writer()),
            $handling,
        ]), outbox: $this->writer());

        $bus->dispatch(new StockReservedEvent('A'), DispatchMode::OUTBOX, new TransportNamesStamp(['orders']));
        $bus->dispatch(new StockReservedEvent('A'), DispatchMode::OUTBOX, new TransportNamesStamp(['orders']));

        $rows = $this->storage->fetchUnpublished(10);
        self::assertCount(2, $rows, 'Both stored: the relay deduplicates them.');
        $stored = (new PhpSerializer())->decode(['body' => $rows[0]->body]);
        self::assertSame('stock-A@orders', (string) $stored->last(DeduplicateStamp::class)?->getKey());
        self::assertNull($stored->last(DispatchAfterCurrentBusStamp::class));
        self::assertTrue($locks->createLock('stock-A')->acquire(), 'No lock is held after the store.');
    }

    public function test_the_sync_bus_stores_the_message_without_an_async_bus(): void
    {
        $bus = new EventBus($this->storingBus(), outbox: $this->writer());

        $envelope = $bus->dispatch(new TaskArchivedEvent('1'), DispatchMode::OUTBOX);

        self::assertNotNull($envelope->last(OutboxStoredStamp::class));
        self::assertCount(1, $this->storage->fetchUnpublished(10));
    }

    public function test_a_bus_without_the_outbox_middleware_fails(): void
    {
        $bus = new EventBus(new MessageBus([]), outbox: $this->writer());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('a middleware of its Messenger bus returned before the bundle\'s OutboxStoreMiddleware');

        $bus->dispatch(new TaskArchivedEvent('1'), DispatchMode::OUTBOX);
    }

    public function test_the_transaction_is_checked_before_the_stamp_pipeline_and_the_bus(): void
    {
        $pipeline = new class implements StampDecider {
            public int $calls = 0;

            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                ++$this->calls;

                return $stamps;
            }
        };
        $noTransaction = new class implements TransactionalOutbox {
            public function isInTransaction(): bool
            {
                return false;
            }
        };
        $writer = new OutboxWriter($this->storage, new PhpSerializer(), transaction: $noTransaction, requireTransaction: true);
        $bus = new EventBus($this->neverCalled(), null, null, new StampsDecider([$pipeline]), outbox: $writer);

        try {
            $bus->dispatch(new TaskArchivedEvent('1'), DispatchMode::OUTBOX);
            self::fail('The store should have been refused.');
        } catch (OutboxRequiresTransactionException) {
        }

        // A rate limiter in the pipeline would otherwise consume a token for a refused store.
        self::assertSame(0, $pipeline->calls);
    }

    public function test_the_relay_keeps_the_stamps_stored_by_the_middleware_of_the_caller(): void
    {
        // Like Symfony's router_context middleware: it appends the context of the current process.
        $context = new class implements MiddlewareInterface {
            public string $context = 'caller';

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return $stack->next()->handle($envelope->with(new DummyStamp($this->context)), $stack);
            }
        };
        $sent = new class implements MiddlewareInterface {
            public ?Envelope $envelope = null;

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return $this->envelope = $envelope;
            }
        };
        $messenger = new MessageBus([new OutboxPrepareMiddleware(), $context, new OutboxStoreMiddleware($this->writer()), $sent]);
        (new EventBus($messenger, outbox: $this->writer()))->dispatch(new TaskArchivedEvent('1'), DispatchMode::OUTBOX);

        // The relay dispatches the stored envelope on the bus again, in its own process.
        $context->context = 'relay';
        $stored = (new PhpSerializer())->decode(['body' => $this->storage->fetchUnpublished(10)[0]->body]);
        $messenger->dispatch($stored->with(new RelayedFromOutboxStamp([DummyStamp::class => 1])));

        self::assertNotNull($sent->envelope);
        self::assertSame(['caller'], array_map(static fn (DummyStamp $stamp): string => $stamp->name, $sent->envelope->all(DummyStamp::class)));
        self::assertNull($sent->envelope->last(RelayedFromOutboxStamp::class));
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
        $this->expectExceptionMessage(sprintf('Message "%s" was dispatched on the event bus with DispatchMode::OUTBOX (resolved from #[Outbox] or "somework_cqrs.dispatch_modes"), but the transactional outbox is disabled.', TaskArchivedEvent::class));

        (new EventBus($this->neverCalled()))->dispatch(new TaskArchivedEvent('1'));
    }

    public function test_an_explicit_outbox_dispatch_without_the_outbox_names_the_mode(): void
    {
        $this->expectException(OutboxNotConfiguredException::class);
        $this->expectExceptionMessage(sprintf('Message "%s" was dispatched on the command bus with DispatchMode::OUTBOX, but', CreateTaskCommand::class));

        (new CommandBus($this->neverCalled()))->dispatch(new CreateTaskCommand('1', 'a'), DispatchMode::OUTBOX);
    }

    private function writer(): OutboxWriter
    {
        return new OutboxWriter($this->storage, new PhpSerializer());
    }

    private function storingBus(MiddlewareInterface ...$before): MessageBus
    {
        $handling = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new \LogicException('The message should have been stored, not handled.');
            }
        };

        return new MessageBus([new OutboxPrepareMiddleware(), ...$before, new OutboxStoreMiddleware($this->writer()), $handling]);
    }

    private function neverCalled(): MessageBusInterface
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        return $bus;
    }
}
