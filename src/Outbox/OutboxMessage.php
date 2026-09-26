<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use DateTimeImmutable;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function bin2hex;
use function chr;
use function intdiv;
use function json_encode;
use function microtime;
use function ord;
use function random_bytes;
use function random_int;
use function sprintf;
use function strtolower;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * Immutable DTO representing a message persisted in the transactional outbox.
 *
 * Build it with {@see fromEnvelope()} so the body and headers use the same Messenger
 * serializer the relay decodes them with.
 *
 * @api
 */
final class OutboxMessage
{
    /** Lowercase: stored ids are compared as text on some platforms. */
    public readonly string $id;

    public function __construct(
        string $id,
        public readonly string $body,
        public readonly string $headers,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?string $transportName = null,
        /** Attempts to publish the message so far (the relay counts an attempt when it claims the message). */
        public readonly int $attempts = 0,
        /** Error of the last failed attempt, null when there was none or the message was requeued. */
        public readonly ?string $lastError = null,
        /** When a relay claimed the message for an attempt it has not finished; set on a fetched message, that attempt was interrupted. */
        public readonly ?DateTimeImmutable $claimedAt = null,
        /** Retry time of the last attempt, null when the message was never attempted (or requeued). */
        public readonly ?DateTimeImmutable $availableAt = null,
        /** Signature of the id, body and headers, set by the storage when outbox signing is enabled. */
        public readonly ?string $signature = null,
    ) {
        if ('' === $id) {
            throw new \InvalidArgumentException('Outbox message id cannot be empty.');
        }

        $this->id = strtolower($id);

        if ($this->attempts < 0) {
            throw new \InvalidArgumentException('Outbox message attempts cannot be negative.');
        }

        if ('' === $this->body) {
            throw new \InvalidArgumentException('Outbox message body cannot be empty.');
        }
    }

    /**
     * Encodes an envelope (message plus stamps) for the outbox.
     *
     * The id is a time-ordered UUIDv7, so messages stored within the same second are still
     * relayed in the order they were stored.
     *
     * @param string|null $transportName Transport to send the message to; null uses the Messenger routing
     */
    public static function fromEnvelope(Envelope $envelope, SerializerInterface $serializer, ?string $transportName = null, ?DateTimeImmutable $createdAt = null): self
    {
        $encoded = $serializer->encode($envelope);
        // The message class, for the listing of given-up rows and the relay's logs: Messenger's PHP
        // serializer writes no headers (and ignores them when decoding).
        $headers = $encoded['headers'] ?? [];
        $headers['type'] ??= $envelope->getMessage()::class;

        return new self(
            id: self::generateUuidV7(),
            body: $encoded['body'],
            headers: json_encode($headers, JSON_THROW_ON_ERROR),
            createdAt: $createdAt ?? new DateTimeImmutable(),
            transportName: $transportName,
        );
    }

    private static int $lastMilliseconds = 0;

    private static int $sequence = 0;

    /**
     * UUIDv7 whose 12-bit "rand_a" field is a per-process counter, so ids created within the
     * same millisecond by one process keep their creation order.
     */
    private static function generateUuidV7(): string
    {
        $milliseconds = (int) (microtime(true) * 1000);

        if ($milliseconds > self::$lastMilliseconds) {
            self::$lastMilliseconds = $milliseconds;
            self::$sequence = random_int(0, 0x7FF);
        } elseif (++self::$sequence > 0xFFF) {
            ++self::$lastMilliseconds;
            self::$sequence = 0;
        }

        $bytes = '';
        for ($shift = 40; $shift >= 0; $shift -= 8) {
            $bytes .= chr(intdiv(self::$lastMilliseconds, 2 ** $shift) & 0xFF);
        }

        $bytes .= chr(0x70 | (self::$sequence >> 8)).chr(self::$sequence & 0xFF); // version 7 + rand_a
        $tail = random_bytes(8);
        $bytes .= chr((ord($tail[0]) & 0x3F) | 0x80).substr($tail, 1); // RFC 4122 variant + rand_b

        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
