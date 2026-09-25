<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\MessageMetadataProvider;
use SomeWork\CqrsBundle\Policy\RandomCorrelationMetadataProvider;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Support\CausationIdContext;
use SomeWork\CqrsBundle\Support\MessageMetadataProviderResolver;
use SomeWork\CqrsBundle\Support\MessageMetadataStampDecider;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;

#[CoversClass(MessageMetadataStampDecider::class)]
final class MessageMetadataStampDeciderTest extends TestCase
{
    public function test_appends_metadata_stamp_for_supported_messages(): void
    {
        $message = new CreateTaskCommand('123', 'Test');
        $metadataStamp = MessageMetadataStamp::createWithRandomCorrelationId();

        $provider = $this->createMock(MessageMetadataProvider::class);
        $provider->expects(self::once())
            ->method('getStamp')
            ->with($message, DispatchMode::ASYNC)
            ->willReturn($metadataStamp);

        $resolver = MessageMetadataProviderResolver::withoutOverrides($provider);
        $decider = new MessageMetadataStampDecider($resolver, Command::class);

        $stamps = $decider->decide($message, DispatchMode::ASYNC, []);

        self::assertSame([$metadataStamp], $stamps);
    }

    public function test_ignores_null_metadata_stamp(): void
    {
        $message = new CreateTaskCommand('123', 'Test');

        $provider = $this->createMock(MessageMetadataProvider::class);
        $provider->expects(self::once())
            ->method('getStamp')
            ->with($message, DispatchMode::ASYNC)
            ->willReturn(null);

        $existing = new DummyStamp('existing');

        $resolver = MessageMetadataProviderResolver::withoutOverrides($provider);
        $decider = new MessageMetadataStampDecider($resolver, Command::class);

        $stamps = $decider->decide($message, DispatchMode::ASYNC, [$existing]);

        self::assertSame([$existing], $stamps);
    }

    public function test_ignores_messages_of_unexpected_type(): void
    {
        $event = new TaskCreatedEvent('123');

        $provider = $this->createMock(MessageMetadataProvider::class);
        $provider->expects(self::never())->method('getStamp');

        $existing = new DummyStamp('existing');

        $resolver = MessageMetadataProviderResolver::withoutOverrides($provider);
        $decider = new MessageMetadataStampDecider($resolver, Command::class);

        $stamps = $decider->decide($event, DispatchMode::ASYNC, [$existing]);

        self::assertSame([$existing], $stamps);
    }

    public function test_keeps_metadata_supplied_by_the_caller(): void
    {
        $provider = $this->createMock(MessageMetadataProvider::class);
        $provider->expects(self::never())->method('getStamp');
        $decider = new MessageMetadataStampDecider(MessageMetadataProviderResolver::withoutOverrides($provider), Command::class);

        $callerStamp = new MessageMetadataStamp('propagated-correlation-id');

        self::assertSame([$callerStamp], $decider->decide(new CreateTaskCommand('1', 'x'), DispatchMode::SYNC, [$callerStamp]));
    }

    public function test_a_child_message_inherits_the_correlation_id_and_names_its_parent(): void
    {
        $context = new CausationIdContext();
        $parent = MessageMetadataStamp::createWithRandomCorrelationId()->withCorrelationId('flow');
        $context->push($parent);
        $decider = new MessageMetadataStampDecider(MessageMetadataProviderResolver::withoutOverrides(new RandomCorrelationMetadataProvider()), Command::class, $context);

        $first = $decider->decide(new CreateTaskCommand('1', 'x'), DispatchMode::SYNC, []);
        $second = $decider->decide(new CreateTaskCommand('2', 'y'), DispatchMode::SYNC, []);

        self::assertInstanceOf(MessageMetadataStamp::class, $first[0]);
        self::assertInstanceOf(MessageMetadataStamp::class, $second[0]);
        self::assertSame('flow', $first[0]->getCorrelationId());
        self::assertSame($parent->getMessageId(), $first[0]->getCausationId());
        self::assertSame('flow', $second[0]->getCorrelationId());
        self::assertNotSame($first[0]->getMessageId(), $second[0]->getMessageId(), 'Each message has its own id.');
        self::assertNotSame($parent->getMessageId(), $first[0]->getMessageId());
    }

    public function test_a_causation_id_set_by_the_provider_is_kept(): void
    {
        $context = new CausationIdContext();
        $context->push(new MessageMetadataStamp('flow'));
        $own = new MessageMetadataStamp('own-flow', [], 'own-parent');
        $provider = $this->createMock(MessageMetadataProvider::class);
        $provider->method('getStamp')->willReturn($own);
        $decider = new MessageMetadataStampDecider(MessageMetadataProviderResolver::withoutOverrides($provider), Command::class, $context);

        self::assertSame([$own], $decider->decide(new CreateTaskCommand('1', 'x'), DispatchMode::SYNC, []));
    }
}
