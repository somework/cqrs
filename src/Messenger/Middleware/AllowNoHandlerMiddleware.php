<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger\Middleware;

use SomeWork\CqrsBundle\Contract\Event;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Ignores missing handlers for event messages to keep dispatching fire-and-forget.
 */
final class AllowNoHandlerMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (NoHandlerForMessageException $exception) {
            $message = $envelope->getMessage();

            if (!$message instanceof Event) {
                throw $exception;
            }

            return $envelope;
        }
    }
}
