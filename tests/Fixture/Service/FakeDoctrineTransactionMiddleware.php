<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Service;

use SomeWork\CqrsBundle\Stamp\StoreInOutboxStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Stands in for DoctrineBundle's "doctrine_transaction" middleware, which flushes the entity
 * manager: a message stored in the outbox must not reach it in the caller's transaction.
 */
final class FakeDoctrineTransactionMiddleware implements MiddlewareInterface
{
    public int $calls = 0;

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null !== $envelope->last(StoreInOutboxStamp::class)) {
            throw new \LogicException('doctrine_transaction ran when the message was stored.');
        }
        ++$this->calls;

        return $stack->next()->handle($envelope, $stack);
    }
}
