<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Messenger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Messenger\OpenTelemetryMiddleware;
use SomeWork\CqrsBundle\Messenger\TraceContextCaptureMiddleware;
use SomeWork\CqrsBundle\Stamp\TraceContextStamp;
use SomeWork\CqrsBundle\Support\StampsDecider;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\GenerateReportCommand;
use SomeWork\CqrsBundle\Tests\Fixture\OpenTelemetry\RecordingTracerProvider;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\DispatchAfterCurrentBusMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

use function explode;

/**
 * A message dispatched from a handler running in a worker must stay in the trace of that handler,
 * also when Messenger defers it until the handler has finished (DispatchAfterCurrentBusStamp).
 */
#[CoversClass(TraceContextCaptureMiddleware::class)]
#[CoversClass(OpenTelemetryMiddleware::class)]
final class OpenTelemetryDeferredDispatchTest extends TestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function dispatchKinds(): iterable
    {
        yield 'deferred (default for async dispatch)' => [true];
        yield 'immediate' => [false];
    }

    #[DataProvider('dispatchKinds')]
    public function test_a_child_message_continues_the_trace_of_the_consuming_handler(bool $deferred): void
    {
        $tracer = new RecordingTracerProvider();
        // Serializing, like a real broker: the trace context must survive the round trip.
        $transport = new InMemoryTransport(new PhpSerializer());
        $buses = new class {
            public ?CommandBus $command = null;
        };

        $handlers = new HandlersLocator([
            CreateTaskCommand::class => [static function () use ($buses, $deferred): void {
                $commandBus = $buses->command;
                self::assertNotNull($commandBus);
                $deferred
                    ? $commandBus->dispatchAsync(new GenerateReportCommand('r-1'))
                    : $commandBus->dispatch(new GenerateReportCommand('r-1'), DispatchMode::SYNC);
            }],
        ]);
        $senders = new SendersLocator(
            [CreateTaskCommand::class => ['async'], GenerateReportCommand::class => ['async']],
            new ServiceLocator(['async' => static fn (): InMemoryTransport => $transport]),
        );
        $bus = new MessageBus([
            new TraceContextCaptureMiddleware(),
            new DispatchAfterCurrentBusMiddleware(),
            new OpenTelemetryMiddleware($tracer),
            new SendMessageMiddleware($senders),
            new HandleMessageMiddleware($handlers),
        ]);
        $buses->command = new CommandBus($bus, $bus, null, StampsDecider::withDefaultAsyncDeferral());

        $bus->dispatch(new CreateTaskCommand('t-1', 'parent'));

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));
        (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);

        $consumeTraceId = null;
        foreach ($tracer->builders as $builder) {
            if ('cqrs.consume CreateTaskCommand' === $builder->name) {
                $consumeTraceId = $builder->span?->getContext()->getTraceId();
            }
        }
        self::assertNotNull($consumeTraceId);

        $child = null;
        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof GenerateReportCommand) {
                $child = $envelope;
            }
        }
        self::assertInstanceOf(Envelope::class, $child);
        $traceparent = $child->last(TraceContextStamp::class)?->headers['traceparent'] ?? '';

        self::assertSame($consumeTraceId, explode('-', $traceparent)[1] ?? null);
    }

    public function test_the_capture_middleware_does_not_touch_received_or_stamped_envelopes(): void
    {
        $stamp = new TraceContextStamp(['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01']);
        $bus = new MessageBus([new TraceContextCaptureMiddleware()]);

        $result = $bus->dispatch(new CreateTaskCommand('t-1', 'x'), [$stamp]);

        self::assertSame([$stamp], $result->all(TraceContextStamp::class));
        self::assertSame([], $bus->dispatch(new CreateTaskCommand('t-2', 'x'))->all(TraceContextStamp::class), 'Without an active span there is nothing to capture.');
    }
}
