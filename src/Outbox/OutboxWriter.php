<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use SomeWork\CqrsBundle\Contract\StampDecider;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Support\CausationIdContext;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function array_values;

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
     * @param StampDecider|null       $transports The bundle's transport stamp decider; without it, messages follow the Messenger routing
     * @param CausationIdContext|null $causation  The message being handled, whose flow a stored message continues
     *
     * @internal
     */
    public function __construct(
        private readonly OutboxStorage $storage,
        private readonly SerializerInterface $serializer,
        private readonly ?StampDecider $transports = null,
        private readonly ?CausationIdContext $causation = null,
    ) {
    }

    /**
     * Stores the message with the given stamps, once per transport (so a failing transport is
     * retried alone). Without a transport name, the transports come from the bundle's
     * configuration for asynchronous dispatches; when none is configured, one row follows the
     * Messenger routing when it is relayed.
     *
     * Stored while a handler runs and without a MessageMetadataStamp, the message continues the
     * flow of the handled message: same correlation id, the handled message as cause.
     *
     * @return list<OutboxMessage> The stored rows
     */
    public function store(object $message, ?string $transportName = null, StampInterface ...$stamps): array
    {
        $envelope = new Envelope($message, array_values($stamps));
        $parent = $this->causation?->current();
        if (null !== $parent && null === $envelope->last(MessageMetadataStamp::class)) {
            $envelope = $envelope->with(new MessageMetadataStamp($parent->getCorrelationId(), [], $parent->getMessageId()));
        }
        $stored = [];
        $transports = null !== $transportName ? [$transportName] : $this->transportsFor($message);
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
