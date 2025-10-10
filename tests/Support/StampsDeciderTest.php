<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusDecider;
use SomeWork\CqrsBundle\Support\MessageMetadataProviderResolver;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use SomeWork\CqrsBundle\Support\MessageTransportStampFactory;
use SomeWork\CqrsBundle\Support\MessageTypeAwareStampDecider;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Support\StampDecider;
use SomeWork\CqrsBundle\Support\StampsDecider;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\ServiceLocator;

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
        self::assertSame($expectedStampNames, array_map(static fn (DummyStamp $stamp) => $stamp->name, $stamps));
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

    #[RunInSeparateProcess]
    public function test_with_defaults_for_emits_send_message_stamp_when_configured(): void
    {
        require_once __DIR__.'/../Fixture/Messenger/SendMessageToTransportsStampStub.php';

        $transports = new MessageTransportResolver(new ServiceLocator([
            MessageTransportResolver::DEFAULT_KEY => static fn (): array => ['configured'],
        ]));

        $decider = StampsDecider::withDefaultsFor(
            messageType: Command::class,
            retryPolicies: RetryPolicyResolver::withoutOverrides(),
            serializers: MessageSerializerResolver::withoutOverrides(),
            metadata: MessageMetadataProviderResolver::withoutOverrides(),
            dispatchAfter: DispatchAfterCurrentBusDecider::defaults(),
            transports: $transports,
            transportStampFactory: new MessageTransportStampFactory(),
            transportStampTypes: ['command' => MessageTransportStampFactory::TYPE_SEND_MESSAGE],
        );

        $stamps = $decider->decide(new CreateTaskCommand('1', 'Test'), DispatchMode::SYNC, []);

        $class = MessageTransportStampFactory::SEND_MESSAGE_TO_TRANSPORTS_STAMP_CLASS;
        self::assertInstanceOf($class, $stamps[0]);
    }
}
