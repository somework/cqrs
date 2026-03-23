<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Contract for dispatching event messages.
 *
 * Type-hint this interface in application code to decouple from the concrete
 * bus implementation and enable easy test-double substitution.
 *
 * @api
 */
interface EventBusInterface
{
    public function dispatch(Event $event, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope;

    public function dispatchSync(Event $event, StampInterface ...$stamps): Envelope;

    public function dispatchAsync(Event $event, StampInterface ...$stamps): Envelope;
}
