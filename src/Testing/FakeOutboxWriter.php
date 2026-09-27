<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxWriterInterface;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function array_filter;
use function array_values;

/**
 * Test double for OutboxWriter: records the stored messages without a database. Check them with
 * CqrsAssertionsTrait::assertStoredInOutbox() (each is recorded with DispatchMode::OUTBOX; the
 * transport given to store() is recorded as a TransportNamesStamp).
 *
 * It does not resolve the transports of the configuration (one row per call, for the given
 * transport or none) and checks no transaction.
 *
 * @api
 */
final class FakeOutboxWriter implements OutboxWriterInterface, RecordsBusDispatches
{
    /** @var list<RecordedDispatch<object>> */
    private array $dispatched = [];

    /** @var list<OutboxMessage> */
    private array $rows = [];

    private ?\Throwable $failure = null;

    public function store(object $message, ?string $transportName = null, StampInterface ...$stamps): array
    {
        $stamps = array_values($stamps);
        if (null !== $transportName) {
            $stamps[] = new TransportNamesStamp([$transportName]);
        }
        $this->dispatched[] = new RecordedDispatch($message, DispatchMode::OUTBOX, $stamps);

        if (null !== $this->failure) {
            throw $this->failure;
        }

        $row = OutboxMessage::fromEnvelope(new Envelope($message, array_values(array_filter($stamps, static fn (StampInterface $stamp): bool => !$stamp instanceof TransportNamesStamp))), new PhpSerializer(), $transportName);
        $this->rows[] = $row;

        return [$row];
    }

    /**
     * Makes store() throw, as the real writer does outside a transaction (the message is recorded first).
     */
    public function willThrow(\Throwable $exception): void
    {
        $this->failure = $exception;
    }

    /**
     * @return list<RecordedDispatch<object>>
     */
    public function getDispatched(): array
    {
        return $this->dispatched;
    }

    /**
     * The rows store() returned, encoded with PHP's serializer.
     *
     * @return list<OutboxMessage>
     */
    public function getStoredRows(): array
    {
        return $this->rows;
    }

    public function reset(): void
    {
        $this->dispatched = [];
        $this->rows = [];
        $this->failure = null;
    }
}
