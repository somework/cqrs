<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Messenger;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Messenger\OpenTelemetryMiddleware;
use SomeWork\CqrsBundle\Stamp\TraceContextStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\OpenTelemetry\RecordingTracerProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

#[CoversClass(OpenTelemetryMiddleware::class)]
#[CoversClass(TraceContextStamp::class)]
final class OpenTelemetryMiddlewareTest extends TestCase
{
    private RecordingTracerProvider $tracerProvider;

    protected function setUp(): void
    {
        $this->tracerProvider = new RecordingTracerProvider();
    }

    public function test_dispatch_creates_a_single_producer_span(): void
    {
        $this->dispatch(new Envelope(new CreateTaskCommand('1', 'x')));

        self::assertCount(1, $this->tracerProvider->builders);
        $builder = $this->tracerProvider->builders[0];

        self::assertSame('cqrs.dispatch CreateTaskCommand', $builder->name);
        self::assertSame(SpanKind::KIND_PRODUCER, $builder->kind);
        self::assertSame(CreateTaskCommand::class, $builder->attributes['cqrs.message.class']);
        self::assertSame('command', $builder->attributes['cqrs.message.type']);

        $span = $this->tracerProvider->lastSpan();
        self::assertSame(StatusCode::STATUS_OK, $span->statusCode);
        self::assertTrue($span->ended);
    }

    public function test_dispatch_attaches_the_trace_context_to_the_envelope(): void
    {
        $envelope = $this->dispatch(new Envelope(new CreateTaskCommand('1', 'x')));

        $stamp = $envelope->last(TraceContextStamp::class);
        self::assertInstanceOf(TraceContextStamp::class, $stamp);

        $spanContext = $this->tracerProvider->lastSpan()->getContext();
        self::assertSame(
            '00-'.$spanContext->getTraceId().'-'.$spanContext->getSpanId().'-01',
            $stamp->headers['traceparent'] ?? null,
        );
    }

    public function test_consumed_message_continues_the_propagated_trace(): void
    {
        $dispatched = $this->dispatch(new Envelope(new CreateTaskCommand('1', 'x')));
        $producerContext = $this->tracerProvider->lastSpan()->getContext();

        $this->dispatch($dispatched->with(new ReceivedStamp('async')));

        $consumer = $this->tracerProvider->builders[1];
        self::assertSame('cqrs.consume CreateTaskCommand', $consumer->name);
        self::assertSame(SpanKind::KIND_CONSUMER, $consumer->kind);
        self::assertNotNull($consumer->parent);
        self::assertSame($producerContext->getTraceId(), $consumer->parent->getTraceId());
        self::assertSame($producerContext->getSpanId(), $consumer->parent->getSpanId());
    }

    public function test_failure_is_recorded_once_and_the_span_is_closed(): void
    {
        $failure = new \RuntimeException('Handler failed');

        try {
            (new OpenTelemetryMiddleware($this->tracerProvider))->handle(new Envelope(new CreateTaskCommand('1', 'x')), $this->failingStack($failure));
            self::fail('Expected the handler exception.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        $span = $this->tracerProvider->lastSpan();
        self::assertSame([$failure], $span->exceptions);
        self::assertSame(StatusCode::STATUS_ERROR, $span->statusCode);
        self::assertSame('Handler failed', $span->statusDescription);
        self::assertTrue($span->ended);
        self::assertFalse(Span::getCurrent()->getContext()->isValid(), 'The span scope must be detached.');
    }

    /**
     * @return iterable<string, array{object, string}>
     */
    public static function messageTypes(): iterable
    {
        yield 'command' => [new CreateTaskCommand('1', 'x'), 'command'];
        yield 'query' => [new FindTaskQuery('1'), 'query'];
        yield 'event' => [new TaskCreatedEvent('1'), 'event'];
        yield 'other' => [new \stdClass(), 'unknown'];
    }

    #[DataProvider('messageTypes')]
    public function test_message_type_attribute(object $message, string $type): void
    {
        $this->dispatch(new Envelope($message));

        self::assertSame($type, $this->tracerProvider->builders[0]->attributes['cqrs.message.type']);
    }

    private function dispatch(Envelope $envelope): Envelope
    {
        return (new MessageBus([new OpenTelemetryMiddleware($this->tracerProvider)]))->dispatch($envelope);
    }

    private function failingStack(\Throwable $failure): StackInterface
    {
        $middleware = new class($failure) implements MiddlewareInterface {
            public function __construct(private readonly \Throwable $failure)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw $this->failure;
            }
        };

        return new class($middleware) implements StackInterface {
            public function __construct(private readonly MiddlewareInterface $middleware)
            {
            }

            public function next(): MiddlewareInterface
            {
                return $this->middleware;
            }
        };
    }
}
