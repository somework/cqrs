<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * A transport whose broker cannot be reached: every send fails with a TransportException.
 */
final class UnavailableTransport implements SenderInterface
{
    public int $sendAttempts = 0;

    public function send(Envelope $envelope): Envelope
    {
        ++$this->sendAttempts;

        throw new TransportException('Connection refused');
    }
}
