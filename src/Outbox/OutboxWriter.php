<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Contract\StampDecider;
use Symfony\Component\Messenger\Envelope;
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
     * @param StampDecider|null $transports The bundle's transport stamp decider; without it, messages follow the Messenger routing
     */
    public function __construct(
        private readonly OutboxStorage $storage,
        private readonly SerializerInterface $serializer,
        private readonly ?StampDecider $transports = null,
    ) {
    }

    /**
     * Stores the message with the given stamps, once per transport (so a failing transport is
     * retried alone). Without a transport name, the transports come from the bundle's
     * configuration for asynchronous dispatches; when none is configured, one row follows the
     * Messenger routing when it is relayed.
     *
     * @return list<OutboxMessage> The stored rows
     */
    public function store(object $message, ?string $transportName = null, StampInterface ...$stamps): array
    {
        $envelope = new Envelope($message, array_values($stamps));
        $stored = [];

        foreach (null !== $transportName ? [$transportName] : $this->transportsFor($message) as $transport) {
            $row = OutboxMessage::fromEnvelope($envelope, $this->serializer, $transport);
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
        foreach ($this->transports?->decide($message, DispatchMode::ASYNC, []) ?? [] as $stamp) {
            if ($stamp instanceof TransportNamesStamp && [] !== $stamp->getTransportNames()) {
                return array_values($stamp->getTransportNames());
            }
        }

        return [null];
    }
}
