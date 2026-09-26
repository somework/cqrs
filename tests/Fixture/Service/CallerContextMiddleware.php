<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Service;

use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskArchivedEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Application middleware on the async event bus: rejects an invalid event (as the validation
 * middleware would) and stamps the context of the dispatching process on every dispatch (a tenant,
 * a user), appending to the stamps already there as Symfony's router_context middleware does.
 */
final class CallerContextMiddleware implements MiddlewareInterface
{
    public ?string $context = null;

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(ReceivedStamp::class)) {
            if ($envelope->getMessage() instanceof TaskArchivedEvent && 'invalid' === $envelope->getMessage()->taskId) {
                throw new \InvalidArgumentException('The event is invalid.');
            }
            if (null !== $this->context) {
                $envelope = $envelope->with(new DummyStamp($this->context));
            }
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
