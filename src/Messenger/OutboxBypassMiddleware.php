<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger;

use SomeWork\CqrsBundle\Stamp\StoreInOutboxStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Wraps middleware that belongs to handling a message (Doctrine's "doctrine_transaction" and
 * "doctrine_open_transaction_logger"): a message stored in the outbox skips it, in the caller's
 * transaction (it would flush the caller's entity manager, or report its open transaction), and
 * gets it when the relay dispatches it. Every other message goes through it.
 *
 * @internal
 */
final class OutboxBypassMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly MiddlewareInterface $inner,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null !== $envelope->last(StoreInOutboxStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        return $this->inner->handle($envelope, $stack);
    }
}
