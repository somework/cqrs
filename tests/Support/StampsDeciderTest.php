<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusDecider;
use SomeWork\CqrsBundle\Support\MessageMetadataProviderResolver;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use SomeWork\CqrsBundle\Support\MessageTransportStampFactory;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Support\StampDecider;
use SomeWork\CqrsBundle\Support\StampsDecider;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class StampsDeciderTest extends TestCase
{
    public function test_invokes_registered_deciders_in_order(): void
    {
        $message = new CreateTaskCommand('1', 'Test');
        $initialStamps = [new DummyStamp('base')];

        $first = new class implements StampDecider {
            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                $stamps[] = new DummyStamp('first');

                return $stamps;
            }
        };

        $second = new class implements StampDecider {
            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                $stamps[] = new DummyStamp('second');

                return $stamps;
            }
        };

        $decider = new StampsDecider([$first, $second]);
        $stamps = $decider->decide($message, DispatchMode::ASYNC, $initialStamps);

        self::assertCount(3, $stamps);
        self::assertSame('base', $stamps[0]->name);
        self::assertSame('first', $stamps[1]->name);
        self::assertSame('second', $stamps[2]->name);
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
