<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use DateTimeImmutable;

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
        /** The SHA-256 of the body (hexadecimal), to recognise a row before signing it. */
        public readonly ?string $bodyDigest = null,
    ) {
    }
}
