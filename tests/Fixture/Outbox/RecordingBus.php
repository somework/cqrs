<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SentStamp;

/**
 * Records dispatched messages and pretends they were sent, or fails every dispatch.
 */
final class RecordingBus implements MessageBusInterface
{
    /** @var list<class-string> */
    private array $messageClasses = [];

    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = Envelope::wrap($message, $stamps);
        $this->messageClasses[] = $envelope->getMessage()::class;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $envelope->with(new SentStamp('transport'));
    }

    /**
     * @return list<class-string>
     */
    public function messageClasses(): array
    {
        return $this->messageClasses;
    }
}
