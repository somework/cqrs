<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RequiresMethod;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use SomeWork\CqrsBundle\Exception\DuplicateMessageException;
use SomeWork\CqrsBundle\Stamp\IdempotencyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\ObservabilityTestKernel;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ChargePaymentCommand;
use SomeWork\CqrsBundle\Tests\Fixture\OpenTelemetry\RecordingTracerProvider;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

#[CoversNothing]
final class ObservabilityIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return ObservabilityTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    public function test_dispatch_is_traced_through_the_compiled_container(): void
    {
        $this->commandBus()->dispatchSync(new ChargePaymentCommand('p-1'));

        $tracerProvider = self::getContainer()->get(RecordingTracerProvider::class);
        self::assertInstanceOf(RecordingTracerProvider::class, $tracerProvider);
        self::assertSame(['cqrs.dispatch ChargePaymentCommand'], array_map(static fn ($builder): string => $builder->name, $tracerProvider->builders));
    }

    #[RequiresMethod(DeduplicateStamp::class, '__construct')]
    public function test_duplicate_synchronous_dispatch_is_reported(): void
    {
        self::assertSame('charged:p-2', $this->commandBus()->dispatchSync(new ChargePaymentCommand('p-2'), new IdempotencyStamp('p-2')));

        $this->expectException(DuplicateMessageException::class);

        $this->commandBus()->dispatchSync(new ChargePaymentCommand('p-2'), new IdempotencyStamp('p-2'));
    }

    #[RequiresMethod(DeduplicateStamp::class, '__construct')]
    public function test_failed_synchronous_dispatch_can_be_retried_with_the_same_idempotency_key(): void
    {
        try {
            $this->commandBus()->dispatchSync(new ChargePaymentCommand('p-3', failOnFirstAttempt: true), new IdempotencyStamp('p-3'));
            self::fail('Expected the first attempt to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Payment gateway unavailable', $exception->getMessage());
        }

        self::assertSame('charged:p-3', $this->commandBus()->dispatchSync(new ChargePaymentCommand('p-3', failOnFirstAttempt: true), new IdempotencyStamp('p-3')));

        $recorder = self::getContainer()->get(TaskRecorder::class);
        self::assertInstanceOf(TaskRecorder::class, $recorder);
        self::assertSame(3, $recorder->attempt('p-3'));
    }

    private function commandBus(): CommandBusInterface
    {
        $bus = self::getContainer()->get(CommandBusInterface::class);
        self::assertInstanceOf(CommandBusInterface::class, $bus);

        return $bus;
    }
}
