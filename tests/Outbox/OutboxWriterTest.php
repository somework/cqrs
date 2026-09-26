<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Outbox\TransactionalOutbox;
use SomeWork\CqrsBundle\Contract\StampDecider;
use SomeWork\CqrsBundle\Exception\OutboxRequiresTransactionException;
use SomeWork\CqrsBundle\Exception\UnknownOutboxTransportException;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\Outbox\Relay\RelayOnTerminateSubscriber;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Stamp\TraceContextStamp;
use SomeWork\CqrsBundle\Support\CausationIdContext;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\StockReservedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\RecordingRelayCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Lock\Store\CombinedStore;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Lock\Strategy\UnanimousStrategy;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\AddDefaultStampsMiddleware;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function array_map;

#[CoversClass(OutboxWriter::class)]
#[CoversClass(UnknownOutboxTransportException::class)]
final class OutboxWriterTest extends TestCase
{
    private InMemoryOutboxStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryOutboxStorage();
    }

    public function test_stores_the_message_with_its_stamps_for_the_given_transport(): void
    {
        $rows = (new OutboxWriter($this->storage, new PhpSerializer(), $this->transports(['async'])))
            ->store(new CreateTaskCommand('1', 'a'), 'orders', new DelayStamp(5000));

        self::assertCount(1, $rows);
        self::assertSame('orders', $rows[0]->transportName, 'A given transport wins over the configuration.');
        self::assertSame([$rows[0]], $this->storage->fetchUnpublished(10));

        $envelope = (new PhpSerializer())->decode(['body' => $rows[0]->body]);
        $decoded = $envelope->getMessage();
        self::assertInstanceOf(CreateTaskCommand::class, $decoded);
        self::assertSame('1', $decoded->id);
        self::assertSame(5000, $envelope->last(DelayStamp::class)?->getDelay());
    }

    public function test_a_row_for_an_unknown_transport_is_refused_before_anything_is_stored(): void
    {
        $writer = new OutboxWriter($this->storage, new PhpSerializer(), transportNames: ['async', 'orders']);

        $writer->store(new CreateTaskCommand('1', 'a'), 'orders');
        foreach ([static fn () => $writer->store(new CreateTaskCommand('2', 'b'), 'ordrs'), static fn () => $writer->storeEnvelope(new Envelope(new CreateTaskCommand('3', 'c'), [new TransportNamesStamp(['async', 'ordrs'])]))] as $store) {
            try {
                $store();
                self::fail('Expected the store to be refused.');
            } catch (UnknownOutboxTransportException $exception) {
                self::assertSame('ordrs', $exception->transportName);
                self::assertStringContainsString('"ordrs" is not a Messenger transport (defined: async, orders)', $exception->getMessage());
            }
        }

        self::assertCount(1, $this->storage->fetchUnpublished(10), 'No row of a refused message, not even for its known transport.');
    }

    #[RequiresMethod(DeduplicateStamp::class, '__construct')]
    public function test_a_deduplicate_stamp_is_refused_when_the_lock_store_keys_stay_local(): void
    {
        // The relay's deduplication would lock the key and fail to send it on every attempt. The
        // store is the one the application runs with, read when a DeduplicateStamp is stored.
        $lockStore = new class {
            public int $reads = 0;

            public function __invoke(): FlockStore
            {
                ++$this->reads;

                return new FlockStore();
            }
        };
        $writer = new OutboxWriter($this->storage, new PhpSerializer(), lockStore: $lockStore(...));
        $writer->store(new CreateTaskCommand('1', 'a'), 'async');
        self::assertSame(0, $lockStore->reads, 'Not read without a DeduplicateStamp.');

        foreach ([static fn () => $writer->store(new CreateTaskCommand('2', 'b'), 'async', new DeduplicateStamp('key')), static fn () => $writer->storeEnvelope(new Envelope(new CreateTaskCommand('3', 'c'), [new DeduplicateStamp('key')]))] as $store) {
            try {
                $store();
                self::fail('Expected the store to be refused.');
            } catch (\LogicException $exception) {
                self::assertStringContainsString('was not stored in the outbox: its DeduplicateStamp', $exception->getMessage());
                self::assertStringContainsString('('.FlockStore::class.')', $exception->getMessage());
            }
        }

        self::assertSame(1, $lockStore->reads);
        self::assertCount(1, $this->storage->fetchUnpublished(10));
    }

    #[RequiresMethod(DeduplicateStamp::class, '__construct')]
    public function test_a_deduplicate_stamp_is_stored_with_a_lock_store_whose_keys_can_be_sent(): void
    {
        $writer = new OutboxWriter($this->storage, new PhpSerializer(), lockStore: static fn (): InMemoryStore => new InMemoryStore());

        $writer->store(new CreateTaskCommand('1', 'a'), 'async', new DeduplicateStamp('key'));

        self::assertCount(1, $this->storage->fetchUnpublished(10));
    }

    #[RequiresMethod(AddDefaultStampsMiddleware::class, 'handle')]
    public function test_a_deduplicate_stamp_among_the_default_stamps_is_scoped_to_each_transport(): void
    {
        // Otherwise the relay's bus adds the same key to every row, and drops all but the first.
        $rows = (new OutboxWriter($this->storage, new PhpSerializer()))->store(new StockReservedEvent('A'), null);
        self::assertCount(1, $rows);

        $rows = (new OutboxWriter($this->storage, new PhpSerializer(), $this->transports(['t1', 't2'])))->store(new StockReservedEvent('B'));

        self::assertSame(['stock-B@t1', 'stock-B@t2'], array_map(static fn (OutboxMessage $row): string => (string) (new PhpSerializer())->decode(['body' => $row->body])->last(DeduplicateStamp::class)?->getKey(), $rows));
    }

    #[RequiresMethod(DeduplicateStamp::class, '__construct')]
    public function test_a_lock_store_combining_a_local_store_is_refused(): void
    {
        $combined = new CombinedStore([new InMemoryStore(), new FlockStore()], new UnanimousStrategy());
        $writer = new OutboxWriter($this->storage, new PhpSerializer(), lockStore: static fn (): CombinedStore => $combined);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('('.FlockStore::class.')');

        $writer->store(new CreateTaskCommand('1', 'a'), 'async', new DeduplicateStamp('key'));
    }

    #[RequiresMethod(AddDefaultStampsMiddleware::class, 'handle')]
    public function test_a_deduplicate_stamp_among_the_default_stamps_of_the_message_is_refused_too(): void
    {
        // The relay's bus adds it (add_default_stamps_middleware).
        $writer = new OutboxWriter($this->storage, new PhpSerializer(), lockStore: static fn (): FlockStore => new FlockStore());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('its DeduplicateStamp');

        $writer->store(new StockReservedEvent('A'), 'async');
    }

    public function test_tells_the_relay_on_terminate_that_messages_were_stored(): void
    {
        $relay = new RecordingRelayCommand();
        $afterStore = new RelayOnTerminateSubscriber(static fn (): Command => $relay);
        $writer = new OutboxWriter($this->storage, new PhpSerializer(), afterStore: $afterStore);

        $afterStore->relay();
        self::assertCount(0, $relay->runs);

        $writer->store(new CreateTaskCommand('1', 'a'), 'async');
        $afterStore->relay();
        self::assertCount(1, $relay->runs);
    }

    public function test_a_stored_message_continues_the_current_trace_when_opentelemetry_is_enabled(): void
    {
        $scope = Span::wrap(SpanContext::create('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', TraceFlags::SAMPLED))->activate();
        try {
            $traced = (new OutboxWriter($this->storage, new PhpSerializer(), $this->transports(['async']), captureTraceContext: true))->store(new CreateTaskCommand('1', 'a'));
            $untraced = (new OutboxWriter($this->storage, new PhpSerializer(), $this->transports(['async'])))->store(new CreateTaskCommand('2', 'a'));
        } finally {
            $scope->detach();
        }

        $stamp = (new PhpSerializer())->decode(['body' => $traced[0]->body])->last(TraceContextStamp::class);
        self::assertInstanceOf(TraceContextStamp::class, $stamp);
        self::assertStringContainsString('4bf92f3577b34da6a3ce929d0e0e4736', $stamp->headers['traceparent'] ?? '');
        self::assertNull((new PhpSerializer())->decode(['body' => $untraced[0]->body])->last(TraceContextStamp::class));
    }

    public function test_an_envelope_decided_by_a_bus_is_stored_once_per_transport_of_its_stamp(): void
    {
        $envelope = new Envelope(new CreateTaskCommand('1', 'a'), [new TransportNamesStamp(['async', 'audit']), new DispatchAfterCurrentBusStamp(), new DelayStamp(5)]);

        $rows = (new OutboxWriter($this->storage, new PhpSerializer()))->storeEnvelope($envelope);

        self::assertSame(['async', 'audit'], array_map(static fn (OutboxMessage $row): ?string => $row->transportName, $rows));
        foreach ($rows as $row) {
            $decoded = (new PhpSerializer())->decode(['body' => $row->body]);
            self::assertNull($decoded->last(TransportNamesStamp::class), 'The relay adds the transport of the row.');
            self::assertNull($decoded->last(DispatchAfterCurrentBusStamp::class), 'Stored now, never deferred.');
            self::assertNotNull($decoded->last(DelayStamp::class));
        }
    }

    public function test_an_envelope_without_transports_follows_the_routing(): void
    {
        $rows = (new OutboxWriter($this->storage, new PhpSerializer()))->storeEnvelope(new Envelope(new CreateTaskCommand('1', 'a')));

        self::assertCount(1, $rows);
        self::assertNull($rows[0]->transportName);
    }

    public function test_storing_outside_a_transaction_is_refused_when_required(): void
    {
        $transaction = new class implements TransactionalOutbox {
            public bool $active = false;

            public function isInTransaction(): bool
            {
                return $this->active;
            }
        };
        $writer = new OutboxWriter($this->storage, new PhpSerializer(), transaction: $transaction, requireTransaction: true);

        foreach ([static fn () => $writer->store(new CreateTaskCommand('1', 'a')), static fn () => $writer->storeEnvelope(new Envelope(new CreateTaskCommand('1', 'a')))] as $store) {
            try {
                $store();
                self::fail('Expected the store to be refused.');
            } catch (OutboxRequiresTransactionException $exception) {
                self::assertStringContainsString('was not stored in the outbox: no transaction is open on the outbox connection', $exception->getMessage());
            }
        }
        self::assertSame([], $this->storage->fetchUnpublished(10));

        $transaction->active = true;
        self::assertCount(1, $writer->store(new CreateTaskCommand('1', 'a')));

        // Without the requirement, or with a storage that cannot tell, it stores anyway.
        $transaction->active = false;
        self::assertCount(1, (new OutboxWriter($this->storage, new PhpSerializer(), transaction: $transaction))->store(new CreateTaskCommand('2', 'a')));
        self::assertCount(1, (new OutboxWriter($this->storage, new PhpSerializer(), requireTransaction: true))->store(new CreateTaskCommand('3', 'a')));
    }

    public function test_stores_one_row_per_configured_transport(): void
    {
        // So a failing transport is retried alone, instead of sending the message to the others again.
        $rows = (new OutboxWriter($this->storage, new PhpSerializer(), $this->transports(['async', 'audit'])))->store(new CreateTaskCommand('1', 'a'));

        self::assertSame(['async', 'audit'], array_map(static fn ($row): ?string => $row->transportName, $rows));
        self::assertCount(2, $this->storage->fetchUnpublished(10));
    }

    #[RequiresMethod(DeduplicateStamp::class, 'getKey')]
    public function test_rows_of_several_transports_get_their_own_deduplication_key(): void
    {
        // Rows sharing a key would drop each other when relayed: the second transport would never get it.
        $rows = (new OutboxWriter($this->storage, new PhpSerializer(), $this->transports(['async', 'audit'])))
            ->store(new CreateTaskCommand('1', 'a'), null, new DeduplicateStamp('task-1', 60.0));

        $keys = array_map(static function ($row): string {
            $stamp = (new PhpSerializer())->decode(['body' => $row->body, 'headers' => []])->last(DeduplicateStamp::class);
            self::assertInstanceOf(DeduplicateStamp::class, $stamp);
            self::assertSame(60.0, $stamp->getTtl());

            return (string) $stamp->getKey();
        }, $rows);
        self::assertSame(['task-1@async', 'task-1@audit'], $keys);

        // Also for rows stored one by one per transport; a row that follows the routing keeps the key.
        $writer = new OutboxWriter($this->storage, new PhpSerializer());
        $single = $writer->store(new CreateTaskCommand('2', 'b'), 'async', new DeduplicateStamp('task-2'));
        self::assertSame('task-2@async', (string) (new PhpSerializer())->decode(['body' => $single[0]->body, 'headers' => []])->last(DeduplicateStamp::class)?->getKey());
        $routed = $writer->store(new CreateTaskCommand('3', 'c'), null, new DeduplicateStamp('task-3'));
        self::assertSame('task-3', (string) (new PhpSerializer())->decode(['body' => $routed[0]->body, 'headers' => []])->last(DeduplicateStamp::class)?->getKey());
    }

    public function test_a_message_stored_by_a_handler_continues_its_flow(): void
    {
        $context = new CausationIdContext();
        $writer = new OutboxWriter($this->storage, new PhpSerializer(), null, $context);
        $handled = new MessageMetadataStamp('flow', [], null, 'handled-message');
        $decode = static fn ($row) => (new PhpSerializer())->decode(['body' => $row->body, 'headers' => []])->last(MessageMetadataStamp::class);

        self::assertNull($decode($writer->store(new CreateTaskCommand('1', 'a'), 'async')[0]), 'Outside a handler nothing is added.');

        $context->push($handled);
        $child = $decode($writer->store(new CreateTaskCommand('2', 'b'), 'async')[0]);
        self::assertInstanceOf(MessageMetadataStamp::class, $child);
        self::assertSame('flow', $child->getCorrelationId());
        self::assertSame('handled-message', $child->getCausationId());
        self::assertNotSame('handled-message', $child->getMessageId());

        $own = new MessageMetadataStamp('own');
        self::assertSame('own', $decode($writer->store(new CreateTaskCommand('3', 'c'), 'async', $own)[0])?->getCorrelationId(), 'A stamp passed by the caller is kept.');
    }

    public function test_without_a_configured_transport_the_row_follows_the_routing(): void
    {
        self::assertNull((new OutboxWriter($this->storage, new PhpSerializer(), $this->transports([])))->store(new CreateTaskCommand('1', 'a'))[0]->transportName);
        self::assertNull((new OutboxWriter($this->storage, new PhpSerializer()))->store(new CreateTaskCommand('2', 'b'))[0]->transportName);
    }

    /**
     * @param list<string> $names
     */
    private function transports(array $names): StampDecider
    {
        return new class($names) implements StampDecider {
            /**
             * @param list<string> $names
             */
            public function __construct(private readonly array $names)
            {
            }

            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                TestCase::assertSame(DispatchMode::ASYNC, $mode);

                return [] === $this->names ? $stamps : [...$stamps, new TransportNamesStamp($this->names)];
            }
        };
    }
}
