<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger;

use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Support\CausationIdContext;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Pushes the metadata stamp of the current message onto the CausationIdContext stack
 * before handler execution and pops it after (even on exception).
 *
 * Child messages dispatched during handler execution inherit the parent's correlation id
 * and get its message id as causation id.
 *
 * @internal
 */
final class CausationIdMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CausationIdContext $causationIdContext,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $metadataStamp = $envelope->last(MessageMetadataStamp::class);

        if (null === $metadataStamp) {
            return $stack->next()->handle($envelope, $stack);
        }

        /* @var MessageMetadataStamp $metadataStamp */
        $this->causationIdContext->push($metadataStamp);

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->causationIdContext->pop();
        }
    }
}
