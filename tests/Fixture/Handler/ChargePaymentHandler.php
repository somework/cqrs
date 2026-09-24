<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ChargePaymentCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

#[AsCommandHandler(command: ChargePaymentCommand::class)]
final class ChargePaymentHandler
{
    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    public function __invoke(ChargePaymentCommand $command): string
    {
        $attempt = $this->recorder->attempt($command->paymentId);

        if ($command->failOnFirstAttempt && 1 === $attempt) {
            throw new \RuntimeException('Payment gateway unavailable');
        }

        return 'charged:'.$command->paymentId;
    }
}
