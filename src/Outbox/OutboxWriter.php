<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use SomeWork\CqrsBundle\Contract\Outbox\TransactionalOutbox;
use SomeWork\CqrsBundle\Contract\StampDecider;
use SomeWork\CqrsBundle\Exception\OutboxRequiresTransactionException;
use SomeWork\CqrsBundle\Exception\UnknownOutboxTransportException;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Stamp\TraceContextStamp;
use SomeWork\CqrsBundle\Support\CausationIdContext;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function array_values;
use function in_array;
use function sprintf;

/**
 * Stores messages in the outbox: call it inside the database transaction of the business change.
 *
 * It encodes messages with the outbox serializer, and sends them where an asynchronous dispatch
 * through the CQRS buses would: the transports configured for the message
 * ("transports.command_async", "transports.event_async") or named by #[Asynchronous(transport: ...)].
 *
 * @api
 */
final class OutboxWriter
{
    /**
     * Get the writer from the container (service "somework_cqrs.outbox.writer", autowired as
     * OutboxWriter): the constructor takes internal services and may change in any release.
     *
     * @param StampDecider|null        $transports          The bundle's transport stamp decider; without it, messages follow the Messenger routing
     * @param CausationIdContext|null  $causation           The message being handled, whose flow a stored message continues
     * @param bool                     $captureTraceContext Stores the current OpenTelemetry trace context, so the relayed message continues the trace
     * @param TransactionalOutbox|null $transaction         The storage behind any decorator, when it can tell whether a transaction is open
     * @param bool                     $requireTransaction  Refuses to store outside a transaction (outbox.require_transaction)
     * @param list<string>|null        $transportNames      The Messenger transports; a row for another transport is refused
     * @param bool                     $lockKeysStayLocal   The lock store ties its keys to the process: a message with a DeduplicateStamp is refused
     *
     * @internal
     */
    public function __construct(
        private readonly OutboxStorage $storage,
        private readonly SerializerInterface $serializer,
        private readonly ?StampDecider $transports = null,
        private readonly ?CausationIdContext $causation = null,
        private readonly bool $captureTraceContext = false,
        private readonly ?TransactionalOutbox $transaction = null,
        private readonly bool $requireTransaction = false,
        private readonly ?array $transportNames = null,
        private readonly bool $lockKeysStayLocal = false,
    ) {
    }

    /**
     * Stores the message with the given stamps, once per transport (so a failing transport is
     * retried alone). Without a transport name, the transports come from the bundle's
     * configuration for asynchronous dispatches; when none is configured, one row follows the
     * Messenger routing when it is relayed.
     *
     * Stored while a handler runs and without a MessageMetadataStamp, the message continues the
     * flow of the handled message: same correlation id, the handled message as cause. With
     * OpenTelemetry, it also continues the current trace when it is relayed.
     *
     * @throws OutboxRequiresTransactionException outside a transaction on the outbox connection (outbox.require_transaction)
     * @throws UnknownOutboxTransportException    for a transport that is not a Messenger transport
     * @throws \LogicException                    for a DeduplicateStamp with a lock store whose keys cannot be sent
     *
     * @return list<OutboxMessage> The stored rows
     */
    public function store(object $message, ?string $transportName = null, StampInterface ...$stamps): array
    {
        $this->assertCanStore($message);

        $envelope = new Envelope($message, array_values($stamps));
        $parent = $this->causation?->current();
        if (null !== $parent && null === $envelope->last(MessageMetadataStamp::class)) {
            $envelope = $envelope->with(new MessageMetadataStamp($parent->getCorrelationId(), [], $parent->getMessageId()));
        }

        return $this->storeRows($envelope, null !== $transportName ? [$transportName] : $this->transportsFor($message));
    }

    /**
     * Stores an envelope whose stamps the bus decided (DispatchMode::OUTBOX): one row per transport
     * of its TransportNamesStamp, or one row that follows the Messenger routing without it.
     *
     * @return non-empty-list<OutboxMessage> The stored rows
     *
     * @internal
     */
    public function storeEnvelope(Envelope $envelope): array
    {
        $this->assertCanStore($envelope->getMessage());

        $names = $envelope->last(TransportNamesStamp::class)?->getTransportNames() ?? [];
        // The relay sends each row to its own transport; stored now, never after the current handler.
        $envelope = $envelope->withoutAll(TransportNamesStamp::class)->withoutAll(DispatchAfterCurrentBusStamp::class);

        return $this->storeRows($envelope, [] === $names ? [null] : array_values($names));
    }

    /**
     * @param non-empty-list<string|null> $transports
     *
     * @return non-empty-list<OutboxMessage>
     */
    private function storeRows(Envelope $envelope, array $transports): array
    {
        foreach ($transports as $transport) {
            if (null !== $transport && null !== $this->transportNames && !in_array($transport, $this->transportNames, true)) {
                // Refused before the business change commits: the relay could never send the row.
                throw new UnknownOutboxTransportException($envelope->getMessage()::class, $transport, $this->transportNames);
            }
        }
        if ($this->lockKeysStayLocal && null !== $envelope->last(DeduplicateStamp::class)) {
            // Messenger's deduplication would take the lock in the relay and fail to send its key, on every attempt.
            throw new \LogicException(sprintf('Message "%s" was not stored in the outbox: its DeduplicateStamp (from an IdempotencyStamp, the default stamps of the message or the caller) needs a lock store whose keys can be sent with the message, but the lock store (e.g. "flock", "semaphore", "postgresql+advisory" or "zookeeper") ties its keys to the current process or connection, so the relay could never send it. Configure a store whose keys can be serialized, such as Redis, Memcached or a PDO/DBAL database (framework.lock), or dispatch it without the stamp.', $envelope->getMessage()::class));
        }

        if ($this->captureTraceContext && null === $envelope->last(TraceContextStamp::class)) {
            $headers = [];
            TraceContextPropagator::getInstance()->inject($headers);
            if ([] !== $headers) {
                $envelope = $envelope->with(new TraceContextStamp($headers));
            }
        }
        $stored = [];
        // The deduplication key is scoped to the row's transport: the rows of one message for several
        // transports (stored at once or one by one) would otherwise drop each other when relayed.
        $deduplicate = $envelope->last(DeduplicateStamp::class);

        foreach ($transports as $transport) {
            $row = OutboxMessage::fromEnvelope(
                $deduplicate instanceof DeduplicateStamp && null !== $transport
                    ? $envelope->withoutAll(DeduplicateStamp::class)->with(new DeduplicateStamp((string) $deduplicate->getKey().'@'.$transport, $deduplicate->getTtl(), $deduplicate->onlyDeduplicateInQueue()))
                    : $envelope,
                $this->serializer,
                $transport,
            );
            $this->storage->store($row);
            $stored[] = $row;
        }

        return $stored;
    }

    /**
     * Throws when the message would be stored outside a transaction (outbox.require_transaction).
     *
     * @throws OutboxRequiresTransactionException
     *
     * @internal
     */
    public function assertCanStore(object $message): void
    {
        if ($this->requireTransaction && null !== $this->transaction && !$this->transaction->isInTransaction()) {
            throw new OutboxRequiresTransactionException($message::class);
        }
    }

    /**
     * @return non-empty-list<string|null>
     */
    private function transportsFor(object $message): array
    {
        if ($this->transports instanceof MessageTransportStampDecider) {
            return $this->transports->transportsFor($message, DispatchMode::ASYNC) ?? [null];
        }

        foreach ($this->transports?->decide($message, DispatchMode::ASYNC, []) ?? [] as $stamp) {
            if ($stamp instanceof TransportNamesStamp && [] !== $stamp->getTransportNames()) {
                return array_values($stamp->getTransportNames());
            }
        }

        return [null];
    }
}
