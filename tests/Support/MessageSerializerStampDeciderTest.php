<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
use SomeWork\CqrsBundle\Support\MessageSerializerStampDecider;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\Messenger\Stamp\SerializerStamp;

final class MessageSerializerStampDeciderTest extends TestCase
{
    public function test_appends_serializer_stamp_for_supported_messages(): void
    {
        $message = new CreateTaskCommand('123', 'Test');
        $serializerStamp = new SerializerStamp(['format' => 'json']);

        $serializer = $this->createMock(MessageSerializer::class);
        $serializer->expects(self::once())
            ->method('getStamp')
            ->with($message, DispatchMode::ASYNC)
            ->willReturn($serializerStamp);

        $resolver = MessageSerializerResolver::withoutOverrides($serializer);
        $decider = new MessageSerializerStampDecider($resolver, Command::class);

        $stamps = $decider->decide($message, DispatchMode::ASYNC, []);

        self::assertSame([$serializerStamp], $stamps);
    }

    public function test_ignores_null_serializer_stamp(): void
    {
        $message = new CreateTaskCommand('123', 'Test');

        $serializer = $this->createMock(MessageSerializer::class);
        $serializer->expects(self::once())
            ->method('getStamp')
            ->with($message, DispatchMode::ASYNC)
            ->willReturn(null);

        $existing = new DummyStamp('existing');

        $resolver = MessageSerializerResolver::withoutOverrides($serializer);
        $decider = new MessageSerializerStampDecider($resolver, Command::class);

        $stamps = $decider->decide($message, DispatchMode::ASYNC, [$existing]);

        self::assertSame([$existing], $stamps);
    }

    public function test_ignores_messages_of_unexpected_type(): void
    {
        $event = new TaskCreatedEvent('123');

        $serializer = $this->createMock(MessageSerializer::class);
        $serializer->expects(self::never())->method('getStamp');

        $existing = new DummyStamp('existing');

        $resolver = MessageSerializerResolver::withoutOverrides($serializer);
        $decider = new MessageSerializerStampDecider($resolver, Command::class);

        $stamps = $decider->decide($event, DispatchMode::ASYNC, [$existing]);

        self::assertSame([$existing], $stamps);
    }
}
