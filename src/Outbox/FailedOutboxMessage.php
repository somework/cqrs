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
        /** The message class in a body of Messenger's PHP serializer, read as text without unserializing it. */
        public readonly ?string $bodyClass = null,
        /** {@see self::digest()} of the row, to recognise it before signing it. */
        public readonly ?string $digest = null,
        /**
         * Every class a body of Messenger's PHP serializer instantiates (the envelope, stamps, the message and
         * objects in their properties), read as text; for other bodies, every class token of serialize()'s
         * format in the text. Null when the body was not read.
         *
         * @var list<string>|null
         */
        public readonly ?array $bodyClasses = null,
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
