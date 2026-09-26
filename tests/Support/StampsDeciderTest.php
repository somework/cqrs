<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\MessageMetadataProvider;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Contract\MessageTypeAwareStampDecider;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Contract\StampDecider;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Support\CausationIdContext;
use SomeWork\CqrsBundle\Support\CausationIdStampDecider;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusDecider;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusStampDecider;
use SomeWork\CqrsBundle\Support\IdempotencyStampDecider;
use SomeWork\CqrsBundle\Support\MessageMetadataProviderResolver;
use SomeWork\CqrsBundle\Support\MessageMetadataStampDecider;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
use SomeWork\CqrsBundle\Support\MessageSerializerStampDecider;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use SomeWork\CqrsBundle\Support\RateLimitResolver;
use SomeWork\CqrsBundle\Support\RateLimitStampDecider;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Support\RetryPolicyStampDecider;
use SomeWork\CqrsBundle\Support\SequenceStampDecider;
use SomeWork\CqrsBundle\Support\StampsDecider;
use SomeWork\CqrsBundle\Support\TransportResolverMap;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\SerializerStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

use function array_map;
use function array_slice;
use function assert;
use function is_string;

#[CoversClass(StampsDecider::class)]
final class StampsDeciderTest extends TestCase
{
    /**
     * @param list<string> $expectedExecutionOrder
     * @param list<string> $expectedStampNames
     */
    #[DataProvider('messageScenarios')]
    public function test_invokes_only_matching_deciders_in_registration_order(
        object $message,
        DispatchMode $mode,
        array $expectedExecutionOrder,
        array $expectedStampNames
    ): void {
        $executions = [];
        $initialStamps = [new DummyStamp('base')];

        $recorder = static function (string $name) use (&$executions): void {
            $executions[] = $name;
        };

        $decider = new StampsDecider([
            $this->createTypeDecider('command', [Command::class], $recorder),
            $this->createTypeDecider('event', [Event::class], $recorder),
            $this->createTypeDecider('multi', [Command::class, Event::class], $recorder),
            $this->createGenericDecider('generic', $recorder),
        ]);

        $stamps = $decider->decide($message, $mode, $initialStamps);

        self::assertSame($expectedExecutionOrder, $executions);
        self::assertSame($expectedStampNames, array_map(static function (StampInterface $stamp): string {
            assert($stamp instanceof DummyStamp);

            return $stamp->name;
        }, $stamps));
    }

    /**
     * @return iterable<string, array{object, DispatchMode, list<string>, list<string>}>
     */
    public static function messageScenarios(): iterable
    {
        yield 'command sync' => [
            new CreateTaskCommand('1', 'Test'),
            DispatchMode::SYNC,
            ['command', 'multi', 'generic'],
            ['base', 'command', 'multi', 'generic'],
        ];

        yield 'command async' => [
            new CreateTaskCommand('1', 'Test'),
            DispatchMode::ASYNC,
            ['command', 'multi', 'generic'],
            ['base', 'command', 'multi', 'generic'],
        ];

        yield 'event async' => [
            new TaskCreatedEvent('1'),
            DispatchMode::ASYNC,
            ['event', 'multi', 'generic'],
            ['base', 'event', 'multi', 'generic'],
        ];

        yield 'event sync' => [
            new TaskCreatedEvent('1'),
            DispatchMode::SYNC,
            ['event', 'multi', 'generic'],
            ['base', 'event', 'multi', 'generic'],
        ];

        yield 'multi contract message runs every matching decider' => [
            new class implements Command, Event {
            },
            DispatchMode::SYNC,
            ['command', 'event', 'multi', 'generic'],
            ['base', 'command', 'event', 'multi', 'generic'],
        ];

        yield 'irrelevant message skips type aware deciders' => [
            new class {
            },
            DispatchMode::SYNC,
            ['generic'],
            ['base', 'generic'],
        ];
    }

    public function test_message_types_are_requested_once_per_decider(): void
    {
        $typeAwareDecider = new class implements MessageTypeAwareStampDecider {
            public int $messageTypeLookups = 0;
            public int $decisions = 0;

            public function messageTypes(): array
            {
                ++$this->messageTypeLookups;

                return [Command::class];
            }

            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                ++$this->decisions;

                return $stamps;
            }
        };

        $decider = new StampsDecider([$typeAwareDecider]);
        $message = new CreateTaskCommand('1', 'Test');

        $decider->decide($message, DispatchMode::SYNC, []);
        $decider->decide($message, DispatchMode::ASYNC, []);

        self::assertSame(1, $typeAwareDecider->messageTypeLookups);
        self::assertSame(2, $typeAwareDecider->decisions);
    }

    public function test_decide_logs_per_decider_with_logger(): void
    {
        $message = new CreateTaskCommand('1', 'Test');

        $decider1 = new class implements StampDecider {
            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                $stamps[] = new DummyStamp('d1');

                return $stamps;
            }
        };

        $decider2 = new class implements StampDecider {
            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                return $stamps;
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('debug')
            ->with(
                self::callback(static fn (mixed $v): bool => is_string($v)),
                self::callback(static fn (array $context): bool => isset($context['message']) && isset($context['decider']))
            );

        $stampsDecider = new StampsDecider([$decider1, $decider2], $logger);

        $stampsDecider->decide($message, DispatchMode::SYNC, []);
    }

    public function test_decide_works_without_logger(): void
    {
        $message = new CreateTaskCommand('1', 'Test');

        $decider = new class implements StampDecider {
            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                $stamps[] = new DummyStamp('test');

                return $stamps;
            }
        };

        $stampsDecider = new StampsDecider([$decider]);
        $stamps = $stampsDecider->decide($message, DispatchMode::SYNC, []);

        self::assertCount(1, $stamps);
    }

    public function test_decide_with_zero_deciders_does_not_log(): void
    {
        $message = new CreateTaskCommand('1', 'Test');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())
            ->method('debug');

        $stampsDecider = new StampsDecider([], $logger);
        $stamps = $stampsDecider->decide($message, DispatchMode::SYNC, []);

        self::assertSame([], $stamps);
    }

    public function test_decide_logs_correct_stamp_counts_before_and_after(): void
    {
        $message = new CreateTaskCommand('1', 'Test');

        $decider = new class implements StampDecider {
            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                $stamps[] = new DummyStamp('added1');
                $stamps[] = new DummyStamp('added2');

                return $stamps;
            }
        };

        $logContexts = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->willReturnCallback(static function (string $message, array $context) use (&$logContexts): void {
                $logContexts[] = $context;
            });

        $stampsDecider = new StampsDecider([$decider], $logger);
        $stampsDecider->decide($message, DispatchMode::SYNC, [new DummyStamp('initial')]);

        self::assertSame(1, $logContexts[0]['stamps_before']);
        self::assertSame(3, $logContexts[0]['stamps_after']);
    }

    public function test_decide_logs_the_deciders_that_changed_the_stamps(): void
    {
        $message = new CreateTaskCommand('1', 'Test');

        $unchanged = new class implements StampDecider {
            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                return $stamps;
            }
        };

        $adding = new class implements StampDecider {
            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                return [...$stamps, new DummyStamp('added')];
            }
        };

        $logContexts = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->willReturnCallback(static function (string $message, array $context) use (&$logContexts): void {
                $logContexts[] = $context;
            });

        $stampsDecider = new StampsDecider([$unchanged, $adding], $logger);
        $stampsDecider->decide($message, DispatchMode::SYNC, []);

        self::assertSame($adding::class, $logContexts[0]['decider']);
        self::assertSame(CreateTaskCommand::class, $logContexts[0]['message']);
        self::assertSame(0, $logContexts[0]['stamps_before']);
        self::assertSame(1, $logContexts[0]['stamps_after']);
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function configuredMessages(): iterable
    {
        yield 'command' => [new CreateTaskCommand('1', 'Test')];
        yield 'event' => [new TaskCreatedEvent('1')];
    }

    #[DataProvider('configuredMessages')]
    public function test_the_default_pipeline_adds_the_configured_stamps(object $message): void
    {
        $stamps = $this->configuredPipelineFor($message)->decide($message, DispatchMode::ASYNC, []);

        self::assertSame([
            DelayStamp::class,
            TransportNamesStamp::class,
            SerializerStamp::class,
            MessageMetadataStamp::class,
            DispatchAfterCurrentBusStamp::class,
        ], array_map(static fn (StampInterface $stamp): string => $stamp::class, $stamps));

        $delay = $stamps[0];
        self::assertInstanceOf(DelayStamp::class, $delay);
        self::assertSame(1000, $delay->getDelay());
        $transports = $stamps[1];
        self::assertInstanceOf(TransportNamesStamp::class, $transports);
        self::assertSame(['configured'], $transports->getTransportNames());
        $serializer = $stamps[2];
        self::assertInstanceOf(SerializerStamp::class, $serializer);
        self::assertSame(['groups' => ['configured']], $serializer->getContext());
        $metadata = $stamps[3];
        self::assertInstanceOf(MessageMetadataStamp::class, $metadata);
        self::assertSame('configured-correlation-id', $metadata->getCorrelationId());
    }

    #[DataProvider('configuredMessages')]
    public function test_stamps_passed_by_the_caller_win_over_every_configured_stamp(object $message): void
    {
        $callerStamps = [
            new DelayStamp(60000),
            new TransportNamesStamp(['caller']),
            new SerializerStamp(['groups' => ['caller']]),
            new MessageMetadataStamp('caller-correlation-id'),
            new DispatchAfterCurrentBusStamp(),
            new DummyStamp('unrelated'),
        ];

        $stamps = $this->configuredPipelineFor($message)->decide($message, DispatchMode::ASYNC, $callerStamps);

        // Messenger reads the last stamp of a class: no configured stamp may follow the caller's.
        self::assertSame($callerStamps, $stamps);
    }

    #[DataProvider('configuredMessages')]
    public function test_a_single_caller_stamp_only_replaces_its_own_kind(object $message): void
    {
        $callerTransports = new TransportNamesStamp(['caller']);

        $stamps = $this->configuredPipelineFor($message)->decide($message, DispatchMode::ASYNC, [$callerTransports]);

        self::assertSame([
            TransportNamesStamp::class,
            DelayStamp::class,
            SerializerStamp::class,
            MessageMetadataStamp::class,
            DispatchAfterCurrentBusStamp::class,
        ], array_map(static fn (StampInterface $stamp): string => $stamp::class, $stamps));
        self::assertSame($callerTransports, $stamps[0]);
    }

    /**
     * @return iterable<string, array{object, bool}>
     */
    public static function unconfiguredMessages(): iterable
    {
        foreach (['command' => new CreateTaskCommand('1', 'Test'), 'event' => new TaskCreatedEvent('1')] as $type => $message) {
            yield $type.', a limiter accepts, a parent is handled' => [$message, true];
            yield $type.', no limiter, no parent' => [$message, false];
        }
    }

    /**
     * Every decider of the pipeline with nothing configured for the message: the stamps of the
     * caller pass through unchanged and in order; only the deferral of an asynchronous dispatch
     * (on by default) is added.
     */
    #[DataProvider('unconfiguredMessages')]
    public function test_caller_stamps_pass_unchanged_through_deciders_with_nothing_configured(object $message, bool $limiterAndParent): void
    {
        $callerStamps = [
            new DelayStamp(500),
            new DummyStamp('caller'),
            new BusNameStamp('messenger.bus.caller'),
            new DummyStamp('last'),
        ];
        $causation = new CausationIdContext();
        if ($limiterAndParent) {
            $causation->push(new MessageMetadataStamp('parent-correlation-id'));
        }
        $limiters = $limiterAndParent
            ? [RateLimitResolver::DEFAULT_KEY => static fn (): RateLimiterFactory => new RateLimiterFactory(['id' => 'accepting', 'policy' => 'no_limit'], new InMemoryStorage())]
            : [];
        $messageType = $message instanceof Event ? Event::class : Command::class;
        $noMetadata = new class implements MessageMetadataProvider {
            public function getStamp(object $message, DispatchMode $mode): ?MessageMetadataStamp
            {
                return null;
            }
        };
        $noTransports = new MessageTransportResolver(new ServiceLocator([]));
        $transportMap = new TransportResolverMap(sync: $noTransports, async: $noTransports);

        // In the order of the priorities the bundle registers them with.
        $pipeline = new StampsDecider([
            new RateLimitStampDecider(new RateLimitResolver(new ServiceLocator($limiters)), $messageType),
            new RetryPolicyStampDecider(RetryPolicyResolver::withoutOverrides(), $messageType),
            new MessageTransportStampDecider($transportMap, new TransportResolverMap(sync: $noTransports), $transportMap),
            new MessageSerializerStampDecider(MessageSerializerResolver::withoutOverrides(), $messageType),
            new MessageMetadataStampDecider(MessageMetadataProviderResolver::withoutOverrides($noMetadata), $messageType, $causation),
            new SequenceStampDecider(),
            new CausationIdStampDecider($causation),
            new IdempotencyStampDecider(),
            new DispatchAfterCurrentBusStampDecider(DispatchAfterCurrentBusDecider::defaults()),
        ]);

        self::assertSame($callerStamps, $pipeline->decide($message, DispatchMode::SYNC, $callerStamps));

        $async = $pipeline->decide($message, DispatchMode::ASYNC, $callerStamps);
        self::assertCount(5, $async);
        self::assertSame($callerStamps, array_slice($async, 0, 4));
        self::assertInstanceOf(DispatchAfterCurrentBusStamp::class, $async[4]);
    }

    private function configuredPipelineFor(object $message): StampsDecider
    {
        $retryPolicy = new class implements RetryPolicy {
            public function getStamps(object $message, DispatchMode $mode): array
            {
                return [new DelayStamp(1000)];
            }
        };
        $serializer = new class implements MessageSerializer {
            public function getStamp(object $message, DispatchMode $mode): SerializerStamp
            {
                return new SerializerStamp(['groups' => ['configured']]);
            }
        };
        $metadata = new class implements MessageMetadataProvider {
            public function getStamp(object $message, DispatchMode $mode): MessageMetadataStamp
            {
                return new MessageMetadataStamp('configured-correlation-id');
            }
        };
        $transports = new MessageTransportResolver(new ServiceLocator([
            $message::class => static fn (): array => ['configured'],
        ]));
        $factory = $message instanceof Event ? StampsDecider::withDefaultEventDecorators(...) : StampsDecider::withDefaultCommandDecorators(...);

        return $factory(
            RetryPolicyResolver::withoutOverrides($retryPolicy),
            MessageSerializerResolver::withoutOverrides($serializer),
            MessageMetadataProviderResolver::withoutOverrides($metadata),
            DispatchAfterCurrentBusDecider::defaults(),
            $transports,
            $transports,
        );
    }

    /**
     * @param list<class-string> $messageTypes
     */
    private function createTypeDecider(string $name, array $messageTypes, Closure $recorder): MessageTypeAwareStampDecider
    {
        return new class($name, $messageTypes, $recorder) implements MessageTypeAwareStampDecider {
            /**
             * @param list<class-string> $messageTypes
             */
            public function __construct(
                private readonly string $name,
                /** @var list<class-string> */
                private readonly array $messageTypes,
                private readonly Closure $recorder,
            ) {
            }

            public function messageTypes(): array
            {
                return $this->messageTypes;
            }

            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                ($this->recorder)($this->name);
                $stamps[] = new DummyStamp($this->name);

                return $stamps;
            }
        };
    }

    private function createGenericDecider(string $name, Closure $recorder): StampDecider
    {
        return new class($name, $recorder) implements StampDecider {
            public function __construct(
                private readonly string $name,
                private readonly Closure $recorder,
            ) {
            }

            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                ($this->recorder)($this->name);
                $stamps[] = new DummyStamp($this->name);

                return $stamps;
            }
        };
    }
}
