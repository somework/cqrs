<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Command;

final class ChargePaymentCommand implements Command
{
    public function __construct(
        public readonly string $paymentId,
        public readonly bool $failOnFirstAttempt = false,
    ) {
    }
}
