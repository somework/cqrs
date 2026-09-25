<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use DateTimeImmutable;

use function hash;
use function strlen;

/**
 * A message the relay gave up on.
 *
 * @api
 */
final class FailedOutboxMessage
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $transportName,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $failedAt,
        public readonly int $attempts,
        public readonly ?string $lastError,
        /** The "type" header of the serializer (the message class), when the serializer writes one; the body is never decoded to find it. */
        public readonly ?string $messageType = null,
        /** The message class named in a body of Messenger's PHP serializer, read as text without unserializing it. */
        public readonly ?string $bodyClass = null,
        /** {@see self::digest()} of the row, to recognise it before signing it. */
        public readonly ?string $digest = null,
    ) {
    }

    /**
     * The SHA-256 (hexadecimal) of a row's body and headers: what a signature covers besides the id.
     */
    public static function digest(string $body, string $headers): string
    {
        return hash('sha256', strlen($body).':'.$body.$headers);
    }
}
