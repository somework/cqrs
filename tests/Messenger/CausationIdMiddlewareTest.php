<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Messenger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Messenger\CausationIdMiddleware;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Support\CausationIdContext;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

#[CoversClass(CausationIdMiddleware::class)]
final class CausationIdMiddlewareTest extends TestCase
{
    private CausationIdContext $context;

    private CausationIdMiddleware $middleware;

    protected function setUp(): void
    {
        $this->context = new CausationIdContext();
        $this->middleware = new CausationIdMiddleware($this->context);
    }

    public function test_implements_middleware_interface(): void
    {
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(MiddlewareInterface::class, $this->middleware);
    }

    public function test_pushes_correlation_id_before_handler_and_pops_after(): void
    {
        $message = new class implements Command {};
        $metadataStamp = new MessageMetadataStamp('corr-123');
        $envelope = new Envelope($message, [$metadataStamp]);

        $capturedCurrent = null;
        $nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $nextMiddleware->method('handle')
            ->willReturnCallback(function (Envelope $envelope, StackInterface $stack) use (&$capturedCurrent): Envelope {
                $capturedCurrent = $this->context->current();

                return $envelope;
            });

        $stack = $this->createMock(StackInterface::class);
        $stack->method('next')->willReturn($nextMiddleware);

        $this->middleware->handle($envelope, $stack);

        self::assertSame('corr-123', $capturedCurrent);
        self::assertNull($this->context->current());
    }

    public function test_skips_push_pop_when_no_metadata_stamp(): void
    {
        $message = new class implements Command {};
        $envelope = new Envelope($message);

        $nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $nextMiddleware->method('handle')
            ->willReturnCallback(static fn (Envelope $envelope, StackInterface $stack): Envelope => $envelope);

        $stack = $this->createMock(StackInterface::class);
        $stack->method('next')->willReturn($nextMiddleware);

        $result = $this->middleware->handle($envelope, $stack);

        self::assertNull($this->context->current());
        self::assertSame($envelope->getMessage(), $result->getMessage());
    }

    public function test_pops_on_exception_via_finally(): void
    {
        $message = new class implements Command {};
        $metadataStamp = new MessageMetadataStamp('corr-456');
        $envelope = new Envelope($message, [$metadataStamp]);

        $nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $nextMiddleware->method('handle')
            ->willThrowException(new \RuntimeException('Handler failed'));

        $stack = $this->createMock(StackInterface::class);
        $stack->method('next')->willReturn($nextMiddleware);

        try {
            $this->middleware->handle($envelope, $stack);
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertNull($this->context->current());
    }

    public function test_nested_dispatch_maintains_correct_context_stack(): void
    {
        $outerMessage = new class implements Command {};
        $outerStamp = new MessageMetadataStamp('outer-corr');
        $outerEnvelope = new Envelope($outerMessage, [$outerStamp]);

        $innerMessage = new class implements Command {};
        $innerStamp = new MessageMetadataStamp('inner-corr');
        $innerEnvelope = new Envelope($innerMessage, [$innerStamp]);

        $capturedOuterContext = null;
        $capturedInnerContext = null;
        $capturedAfterInnerPop = null;

        $innerNextMiddleware = $this->createMock(MiddlewareInterface::class);
        $innerNextMiddleware->method('handle')
            ->willReturnCallback(function (Envelope $envelope, StackInterface $stack) use (&$capturedInnerContext): Envelope {
                $capturedInnerContext = $this->context->current();

                return $envelope;
            });

        $innerStack = $this->createMock(StackInterface::class);
        $innerStack->method('next')->willReturn($innerNextMiddleware);

        $middleware = $this->middleware;

        $outerNextMiddleware = $this->createMock(MiddlewareInterface::class);
        $outerNextMiddleware->method('handle')
            ->willReturnCallback(function (Envelope $envelope, StackInterface $stack) use (
                &$capturedOuterContext,
                &$capturedAfterInnerPop,
                $middleware,
                $innerEnvelope,
                $innerStack,
            ): Envelope {
                $capturedOuterContext = $this->context->current();

                // Simulate nested dispatch
                $middleware->handle($innerEnvelope, $innerStack);

                $capturedAfterInnerPop = $this->context->current();

                return $envelope;
            });

        $outerStack = $this->createMock(StackInterface::class);
        $outerStack->method('next')->willReturn($outerNextMiddleware);

        $this->middleware->handle($outerEnvelope, $outerStack);

        self::assertSame('outer-corr', $capturedOuterContext);
        self::assertSame('inner-corr', $capturedInnerContext);
        self::assertSame('outer-corr', $capturedAfterInnerPop);
        self::assertNull($this->context->current());
    }

    public function test_returns_envelope_from_next_middleware(): void
    {
        $message = new class implements Command {};
        $metadataStamp = new MessageMetadataStamp('corr-789');
        $envelope = new Envelope($message, [$metadataStamp]);

        $returnedEnvelope = new Envelope($message, [$metadataStamp, new \Symfony\Component\Messenger\Stamp\HandledStamp('result', 'handler')]);

        $nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $nextMiddleware->method('handle')
            ->willReturn($returnedEnvelope);

        $stack = $this->createMock(StackInterface::class);
        $stack->method('next')->willReturn($nextMiddleware);

        $result = $this->middleware->handle($envelope, $stack);

        self::assertSame($returnedEnvelope, $result);
    }
}
