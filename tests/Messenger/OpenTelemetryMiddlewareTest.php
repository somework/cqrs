<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Messenger;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ScopeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Messenger\OpenTelemetryMiddleware;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

#[CoversClass(OpenTelemetryMiddleware::class)]
final class OpenTelemetryMiddlewareTest extends TestCase
{
    private TracerProviderInterface&MockObject $tracerProvider;

    private TracerInterface&MockObject $tracer;

    protected function setUp(): void
    {
        parent::setUp();

        if (!interface_exists(TracerProviderInterface::class)) {
            self::markTestSkipped('open-telemetry/api is not installed.');
        }

        $this->tracerProvider = $this->createMock(TracerProviderInterface::class);
        $this->tracer = $this->createMock(TracerInterface::class);

        $this->tracerProvider
            ->method('getTracer')
            ->with('somework.cqrs')
            ->willReturn($this->tracer);
    }

    public function test_creates_dispatch_and_handle_spans_on_success(): void
    {
        $message = new class implements Command {};
        $envelope = new Envelope($message);
        $expectedEnvelope = new Envelope($message);

        $dispatchSpan = $this->createMock(SpanInterface::class);
        $dispatchScope = $this->createMock(ScopeInterface::class);
        $handleSpan = $this->createMock(SpanInterface::class);
        $handleScope = $this->createMock(ScopeInterface::class);

        $dispatchSpanBuilder = $this->createSpanBuilder($dispatchSpan, SpanKind::KIND_PRODUCER);
        $handleSpanBuilder = $this->createSpanBuilder($handleSpan, SpanKind::KIND_INTERNAL);

        $this->tracer
            ->expects(self::exactly(2))
            ->method('spanBuilder')
            ->willReturnCallback(function (string $name) use ($dispatchSpanBuilder, $handleSpanBuilder): SpanBuilderInterface {
                return str_starts_with($name, 'cqrs.dispatch')
                    ? $dispatchSpanBuilder
                    : $handleSpanBuilder;
            });

        $dispatchSpan->expects(self::once())->method('activate')->willReturn($dispatchScope);
        $handleSpan->expects(self::once())->method('activate')->willReturn($handleScope);

        $dispatchSpan->expects(self::once())->method('setStatus')->with(StatusCode::STATUS_OK);
        $handleSpan->expects(self::once())->method('setStatus')->with(StatusCode::STATUS_OK);

        $dispatchSpan->expects(self::once())->method('end');
        $handleSpan->expects(self::once())->method('end');

        $dispatchScope->expects(self::once())->method('detach');
        $handleScope->expects(self::once())->method('detach');

        $stack = $this->createStackReturning($expectedEnvelope);

        $middleware = new OpenTelemetryMiddleware($this->tracerProvider);
        $result = $middleware->handle($envelope, $stack);

        self::assertSame($expectedEnvelope, $result);
    }

    public function test_records_exception_and_sets_error_status(): void
    {
        $message = new class implements Command {};
        $envelope = new Envelope($message);
        $exception = new \RuntimeException('Handler failed');

        $dispatchSpan = $this->createMock(SpanInterface::class);
        $dispatchScope = $this->createMock(ScopeInterface::class);
        $handleSpan = $this->createMock(SpanInterface::class);
        $handleScope = $this->createMock(ScopeInterface::class);

        $dispatchSpanBuilder = $this->createSpanBuilder($dispatchSpan, SpanKind::KIND_PRODUCER);
        $handleSpanBuilder = $this->createSpanBuilder($handleSpan, SpanKind::KIND_INTERNAL);

        $this->tracer
            ->method('spanBuilder')
            ->willReturnCallback(function (string $name) use ($dispatchSpanBuilder, $handleSpanBuilder): SpanBuilderInterface {
                return str_starts_with($name, 'cqrs.dispatch')
                    ? $dispatchSpanBuilder
                    : $handleSpanBuilder;
            });

        $dispatchSpan->method('activate')->willReturn($dispatchScope);
        $handleSpan->method('activate')->willReturn($handleScope);

        $handleSpan->expects(self::once())->method('setStatus')->with(StatusCode::STATUS_ERROR, 'Handler failed');
        $handleSpan->expects(self::once())->method('recordException')->with($exception);
        $handleSpan->expects(self::once())->method('end');
        $handleScope->expects(self::once())->method('detach');

        $dispatchSpan->expects(self::once())->method('setStatus')->with(StatusCode::STATUS_ERROR, 'Handler failed');
        $dispatchSpan->expects(self::once())->method('recordException')->with($exception);
        $dispatchSpan->expects(self::once())->method('end');
        $dispatchScope->expects(self::once())->method('detach');

        $stack = $this->createThrowingStack($exception);

        $middleware = new OpenTelemetryMiddleware($this->tracerProvider);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Handler failed');

        $middleware->handle($envelope, $stack);
    }

    public function test_detects_command_message_type(): void
    {
        $this->assertMessageType(new class implements Command {}, 'command');
    }

    public function test_detects_query_message_type(): void
    {
        $this->assertMessageType(new class implements Query {}, 'query');
    }

    public function test_detects_event_message_type(): void
    {
        $this->assertMessageType(new class implements Event {}, 'event');
    }

    public function test_detects_unknown_message_type(): void
    {
        $this->assertMessageType(new \stdClass(), 'unknown');
    }

    public function test_scope_detached_even_on_exception(): void
    {
        $message = new class implements Command {};
        $envelope = new Envelope($message);

        $dispatchSpan = $this->createMock(SpanInterface::class);
        $dispatchScope = $this->createMock(ScopeInterface::class);
        $handleSpan = $this->createMock(SpanInterface::class);
        $handleScope = $this->createMock(ScopeInterface::class);

        $dispatchSpanBuilder = $this->createSpanBuilder($dispatchSpan, SpanKind::KIND_PRODUCER);
        $handleSpanBuilder = $this->createSpanBuilder($handleSpan, SpanKind::KIND_INTERNAL);

        $this->tracer
            ->method('spanBuilder')
            ->willReturnCallback(function (string $name) use ($dispatchSpanBuilder, $handleSpanBuilder): SpanBuilderInterface {
                return str_starts_with($name, 'cqrs.dispatch')
                    ? $dispatchSpanBuilder
                    : $handleSpanBuilder;
            });

        $dispatchSpan->method('activate')->willReturn($dispatchScope);
        $handleSpan->method('activate')->willReturn($handleScope);
        $dispatchSpan->method('setStatus');
        $dispatchSpan->method('recordException');
        $handleSpan->method('setStatus');
        $handleSpan->method('recordException');

        // These are the critical assertions: scopes MUST be detached
        $handleScope->expects(self::once())->method('detach');
        $dispatchScope->expects(self::once())->method('detach');

        $stack = $this->createThrowingStack(new \RuntimeException('fail'));

        $middleware = new OpenTelemetryMiddleware($this->tracerProvider);

        try {
            $middleware->handle($envelope, $stack);
        } catch (\RuntimeException) {
            // Expected
        }
    }

    private function assertMessageType(object $message, string $expectedType): void
    {
        $envelope = new Envelope($message);

        $dispatchSpan = $this->createMock(SpanInterface::class);
        $dispatchScope = $this->createMock(ScopeInterface::class);
        $handleSpan = $this->createMock(SpanInterface::class);
        $handleScope = $this->createMock(ScopeInterface::class);

        $dispatchSpanBuilder = $this->createSpanBuilder($dispatchSpan, SpanKind::KIND_PRODUCER);
        $handleSpanBuilder = $this->createSpanBuilder($handleSpan, SpanKind::KIND_INTERNAL);

        $capturedAttributes = [];

        $dispatchSpanBuilder
            ->method('setAttribute')
            ->willReturnCallback(function (string $key, mixed $value) use ($dispatchSpanBuilder, &$capturedAttributes): SpanBuilderInterface {
                $capturedAttributes[$key] = $value;

                return $dispatchSpanBuilder;
            });

        $this->tracer
            ->method('spanBuilder')
            ->willReturnCallback(function (string $name) use ($dispatchSpanBuilder, $handleSpanBuilder): SpanBuilderInterface {
                return str_starts_with($name, 'cqrs.dispatch')
                    ? $dispatchSpanBuilder
                    : $handleSpanBuilder;
            });

        $dispatchSpan->method('activate')->willReturn($dispatchScope);
        $handleSpan->method('activate')->willReturn($handleScope);
        $dispatchSpan->method('setStatus');
        $handleSpan->method('setStatus');

        $stack = $this->createStackReturning($envelope);

        $middleware = new OpenTelemetryMiddleware($this->tracerProvider);
        $middleware->handle($envelope, $stack);

        self::assertSame($expectedType, $capturedAttributes['cqrs.message.type'] ?? null);
    }

    private function createSpanBuilder(SpanInterface $span, int $spanKind): SpanBuilderInterface&MockObject
    {
        $builder = $this->createMock(SpanBuilderInterface::class);
        $builder->method('setSpanKind')->with($spanKind)->willReturnSelf();
        $builder->method('setAttribute')->willReturnSelf();
        $builder->method('startSpan')->willReturn($span);

        return $builder;
    }

    private function createStackReturning(Envelope $envelope): StackInterface
    {
        $nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $nextMiddleware->method('handle')->willReturn($envelope);

        $stack = $this->createMock(StackInterface::class);
        $stack->method('next')->willReturn($nextMiddleware);

        return $stack;
    }

    private function createThrowingStack(\Throwable $exception): StackInterface
    {
        $nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $nextMiddleware->method('handle')->willThrowException($exception);

        $stack = $this->createMock(StackInterface::class);
        $stack->method('next')->willReturn($nextMiddleware);

        return $stack;
    }
}
