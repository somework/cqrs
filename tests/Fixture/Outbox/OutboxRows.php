<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use DateTimeImmutable;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;

use function bin2hex;
use function random_bytes;
use function sprintf;

/**
 * Puts outbox rows into the states the relay leaves them in, through the storage contract.
 */
final class OutboxRows
{
    /**
     * A failed attempt: the row has $attempts attempts and $error, and is due again at $retryAt
     * (given up when null). The row must be due.
     */
    public static function fail(OutboxStorage $storage, string $id, int $attempts, string $error, ?DateTimeImmutable $retryAt): void
    {
        $token = self::claim($storage, $id, new DateTimeImmutable());
        $storage->recordFailure($id, $token, $attempts, $error, $retryAt);
    }

    /**
     * An attempt the process did not finish: the row stays claimed and is due again at $retryAt.
     * The row must be due.
     *
     * @return string The claim token
     */
    public static function claim(OutboxStorage $storage, string $id, DateTimeImmutable $retryAt): string
    {
        $message = self::due($storage, $id);
        $token = bin2hex(random_bytes(16));
        if ([$message->id] !== $storage->claim([$message], [$message->attempts => $retryAt], $token)) {
            throw new \LogicException(sprintf('Outbox message "%s" could not be claimed.', $id));
        }

        return $token;
    }

    public static function due(OutboxStorage $storage, string $id): OutboxMessage
    {
        foreach ($storage->fetchUnpublished(1000) as $message) {
            if ($message->id === $id) {
                return $message;
            }
        }

        throw new \LogicException(sprintf('Outbox message "%s" is not due.', $id));
    }
}
