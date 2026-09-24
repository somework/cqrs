<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SentStamp;

/**
 * Calls a callback for every dispatched message (which may throw) and pretends it was sent.
 */
final class CallbackBus implements MessageBusInterface
{
    /**
     * @param \Closure(object): void $onDispatch
     */
    public function __construct(private readonly \Closure $onDispatch)
    {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = Envelope::wrap($message, $stamps);
        ($this->onDispatch)($envelope->getMessage());

        return $envelope->with(new SentStamp('transport'));
    }
}
