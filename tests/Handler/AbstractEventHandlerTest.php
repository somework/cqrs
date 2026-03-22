<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Handler\AbstractEventHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\OrderPlacedEvent;
use Symfony\Component\Messenger\Envelope;

#[CoversClass(AbstractEventHandler::class)]
final class AbstractEventHandlerTest extends TestCase
{
    #[Test]
    public function invoke_delegates_to_on(): void
    {
        $event = new OrderPlacedEvent('order-1');
        $handler = new class extends AbstractEventHandler {
            public ?Event $received = null;

            protected function on(Event $event): void
            {
                $this->received = $event;
            }
        };

        $handler($event);

        self::assertSame($event, $handler->received);
    }

    #[Test]
    public function handler_implements_envelope_aware(): void
    {
        $handler = new class extends AbstractEventHandler {
            protected function on(Event $event): void
            {
            }
        };

        self::assertInstanceOf(EnvelopeAware::class, $handler); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function envelope_is_accessible_during_on(): void
    {
        $envelope = new Envelope(new \stdClass());
        $handler = new class extends AbstractEventHandler {
            public ?Envelope $capturedEnvelope = null;

            protected function on(Event $event): void
            {
                $this->capturedEnvelope = $this->getEnvelope();
            }
        };

        $handler->setEnvelope($envelope);
        $handler(new OrderPlacedEvent('order-1'));

        self::assertSame($envelope, $handler->capturedEnvelope);
    }
}
