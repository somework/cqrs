<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Event;
use Symfony\Component\Messenger\Message\DefaultStampsProviderInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Provides a deduplication key and a deferral as default stamps (symfony/messenger 7.4+), which
 * Messenger's add_default_stamps_middleware adds on the bus.
 *
 * @psalm-immutable
 */
final class StockReservedEvent implements Event, DefaultStampsProviderInterface
{
    public function __construct(public readonly string $sku)
    {
    }

    public function getDefaultStamps(): array
    {
        return [new DeduplicateStamp('stock-'.$this->sku), new DispatchAfterCurrentBusStamp()];
    }
}
