<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryAwareMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SerializerStamp;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class CommandBusTest extends TestCase
{
    public function test_dispatch_uses_sync_bus_by_default(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $envelope = new Envelope($command);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with($command, [])
            ->willReturn($envelope);

        $bus = new CommandBus($syncBus, null, RetryPolicyResolver::withoutOverrides(), new NullMessageSerializer());

        self::assertSame($envelope, $bus->dispatch($command));
    }

    public function test_dispatch_to_async_bus_when_configured(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $envelope = new Envelope($command);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with($command, [])
            ->willReturn($envelope);

        $bus = new CommandBus($syncBus, $asyncBus, RetryPolicyResolver::withoutOverrides(), new NullMessageSerializer());

        self::assertSame($envelope, $bus->dispatch($command, DispatchMode::ASYNC));
    }

    public function test_async_dispatch_without_bus_throws_exception(): void
    {
        $command = new CreateTaskCommand('123', 'Test');

        $syncBus = $this->createMock(MessageBusInterface::class);

        $bus = new CommandBus($syncBus, null, RetryPolicyResolver::withoutOverrides(), new NullMessageSerializer());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Asynchronous command bus is not configured.');

        $bus->dispatch($command, DispatchMode::ASYNC);
    }

    public function test_dispatch_appends_retry_and_serializer_stamps(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $retryStamp = new DummyStamp('retry');
        $serializerStamp = new SerializerStamp(['format' => 'json']);

        $policy = $this->createMock(RetryPolicy::class);
        $policy->expects(self::once())
            ->method('getStamps')
            ->with($command, DispatchMode::SYNC)
            ->willReturn([$retryStamp]);

        $serializer = $this->createMock(MessageSerializer::class);
        $serializer->expects(self::once())
            ->method('getStamp')
            ->with($command, DispatchMode::SYNC)
            ->willReturn($serializerStamp);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with(
                $command,
                self::callback(function (array $stamps) use ($retryStamp, $serializerStamp): bool {
                    self::assertCount(2, $stamps);
                    self::assertSame($retryStamp, $stamps[0]);
                    self::assertSame($serializerStamp, $stamps[1]);

                    return true;
                })
            )
            ->willReturn(new Envelope($command));

        $resolver = new RetryPolicyResolver($policy, new ServiceLocator([]));

        $bus = new CommandBus($syncBus, null, $resolver, $serializer);

        $bus->dispatch($command);
    }

    public function test_dispatch_passes_mode_to_retry_policy_and_serializer(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $retryStamp = new DummyStamp('retry');
        $serializerStamp = new SerializerStamp(['format' => 'json']);

        $defaultPolicy = $this->createMock(RetryPolicy::class);
        $defaultPolicy->expects(self::never())->method('getStamps');

        $messagePolicy = $this->createMock(RetryPolicy::class);
        $messagePolicy->expects(self::once())
            ->method('getStamps')
            ->with($command, DispatchMode::ASYNC)
            ->willReturn([$retryStamp]);

        $serializer = $this->createMock(MessageSerializer::class);
        $serializer->expects(self::once())
            ->method('getStamp')
            ->with($command, DispatchMode::ASYNC)
            ->willReturn($serializerStamp);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with(
                $command,
                self::callback(function (array $stamps) use ($retryStamp, $serializerStamp): bool {
                    self::assertSame([$retryStamp, $serializerStamp], $stamps);

                    return true;
                })
            )
            ->willReturn(new Envelope($command));

        $resolver = new RetryPolicyResolver(
            $defaultPolicy,
            new ServiceLocator([
                CreateTaskCommand::class => static fn (): RetryPolicy => $messagePolicy,
            ])
        );

        $bus = new CommandBus($syncBus, $asyncBus, $resolver, $serializer);

        $bus->dispatch($command, DispatchMode::ASYNC);
    }

    public function test_dispatch_uses_retry_policy_bound_to_interface(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $retryStamp = new DummyStamp('retry');

        $defaultPolicy = $this->createMock(RetryPolicy::class);
        $defaultPolicy->expects(self::never())->method('getStamps');

        $interfacePolicy = $this->createMock(RetryPolicy::class);
        $interfacePolicy->expects(self::once())
            ->method('getStamps')
            ->with($command, DispatchMode::SYNC)
            ->willReturn([$retryStamp]);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with(
                $command,
                self::callback(function (array $stamps) use ($retryStamp): bool {
                    self::assertSame([$retryStamp], $stamps);

                    return true;
                })
            )
            ->willReturn(new Envelope($command));

        $resolver = new RetryPolicyResolver(
            $defaultPolicy,
            new ServiceLocator([
                RetryAwareMessage::class => static fn (): RetryPolicy => $interfacePolicy,
            ])
        );

        $bus = new CommandBus($syncBus, null, $resolver, new NullMessageSerializer());

        $bus->dispatch($command);
    }
}
