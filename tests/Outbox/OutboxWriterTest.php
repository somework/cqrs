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
use SomeWork\CqrsBundle\Contract\StampDecider;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Stamp\TraceContextStamp;
use SomeWork\CqrsBundle\Support\CausationIdContext;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function array_map;

#[CoversClass(OutboxWriter::class)]
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
