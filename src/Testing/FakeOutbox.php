<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing;

use SomeWork\CqrsBundle\Attribute\Outbox;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Stamp\OutboxStoredStamp;

use function bin2hex;
use function random_bytes;

/**
 * How the fake buses tell an outbox dispatch without the bundle's configuration: DispatchMode::OUTBOX,
 * or DispatchMode::DEFAULT for a class carrying #[Outbox] ("dispatch_modes" is not known to them).
 *
 * @internal
 */
final class FakeOutbox
{
    public static function isOutboxDispatch(object $message, ?DispatchMode $mode): bool
    {
        return DispatchMode::OUTBOX === $mode
            || (DispatchMode::DEFAULT === $mode && [] !== (new \ReflectionClass($message))->getAttributes(Outbox::class));
    }

    /**
     * The stamp a bus adds to the envelope of a stored message, with a generated row id.
     */
    public static function storedStamp(object $message): OutboxStoredStamp
    {
        $attribute = (new \ReflectionClass($message))->getAttributes(Outbox::class)[0] ?? null;

        return new OutboxStoredStamp(['fake-'.bin2hex(random_bytes(8))], [$attribute?->newInstance()->transport]);
    }
}
