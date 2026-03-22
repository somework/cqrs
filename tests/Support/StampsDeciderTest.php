<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Support\MessageTypeAwareStampDecider;
use SomeWork\CqrsBundle\Support\StampDecider;
use SomeWork\CqrsBundle\Support\StampsDecider;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function assert;
use function is_string;

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

    public function test_decide_logs_once_per_matching_decider(): void
    {
        $message = new CreateTaskCommand('1', 'Test');

        $decider1 = new class implements StampDecider {
            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
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
        $logger->expects(self::exactly(2))
            ->method('debug');

        $stampsDecider = new StampsDecider([$decider1, $decider2], $logger);
        $stampsDecider->decide($message, DispatchMode::SYNC, []);
    }

    public function test_decide_log_context_includes_decider_class(): void
    {
        $message = new CreateTaskCommand('1', 'Test');

        $decider = new class implements StampDecider {
            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
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
        $stampsDecider->decide($message, DispatchMode::SYNC, []);

        self::assertArrayHasKey('decider', $logContexts[0]);
        self::assertSame($decider::class, $logContexts[0]['decider']);
        self::assertSame(CreateTaskCommand::class, $logContexts[0]['message']);
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
