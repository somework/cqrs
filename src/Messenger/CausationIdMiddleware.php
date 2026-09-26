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
        /** False on buses outside "causation_id.buses": their messages only hide the outer message. */
        private readonly bool $track = true,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // A message without metadata is pushed too: the messages its handlers dispatch must not
        // refer to an outer message as their cause.
        $metadataStamp = $envelope->last(MessageMetadataStamp::class);
        $this->causationIdContext->push($this->track && $metadataStamp instanceof MessageMetadataStamp ? $metadataStamp : null);

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->causationIdContext->pop();
        }
    }
}
