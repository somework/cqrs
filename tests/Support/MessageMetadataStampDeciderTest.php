<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\MessageMetadataProvider;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Support\MessageMetadataProviderResolver;
use SomeWork\CqrsBundle\Support\MessageMetadataStampDecider;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;

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
}
