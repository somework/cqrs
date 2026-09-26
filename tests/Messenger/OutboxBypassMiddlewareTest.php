<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Messenger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Messenger\OutboxBypassMiddleware;
use SomeWork\CqrsBundle\Stamp\StoreInOutboxStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskArchivedEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;

#[CoversClass(OutboxBypassMiddleware::class)]
final class OutboxBypassMiddlewareTest extends TestCase
{
    public function test_a_message_stored_in_the_outbox_skips_the_wrapped_middleware(): void
    {
        $inner = new class implements MiddlewareInterface {
            public int $calls = 0;

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ++$this->calls;

                return $stack->next()->handle($envelope, $stack);
            }
        };
        $middleware = new OutboxBypassMiddleware($inner);

        $stored = $middleware->handle(new Envelope(new TaskArchivedEvent('1'), [new StoreInOutboxStamp()]), new StackMiddleware());
        self::assertSame(0, $inner->calls);
        self::assertNotNull($stored->last(StoreInOutboxStamp::class));

        // The relay's dispatch, and every other message, go through it.
        $middleware->handle(new Envelope(new TaskArchivedEvent('1')), new StackMiddleware());
        self::assertSame(1, $inner->calls);
    }
}
