<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

final class OrderPlacedEvent implements AuditLogEvent
{
    public function __construct(public readonly string $orderId)
    {
    }
}
