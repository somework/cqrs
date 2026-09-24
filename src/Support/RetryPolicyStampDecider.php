<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Adds retry policy stamps for supported messages.
 *
 * @internal
 */
final class RetryPolicyStampDecider implements MessageTypeAwareStampDecider
{
    /**
     * @param class-string $messageType
     */
    public function __construct(
        private readonly RetryPolicyResolver $retryPolicies,
        private readonly string $messageType,
    ) {
    }

    public function messageTypes(): array
    {
        return [$this->messageType];
    }

    /**
     * @param array<int, StampInterface> $stamps
     *
     * @return array<int, StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        if (!$message instanceof $this->messageType) {
            return $stamps;
        }

        $policy = $this->retryPolicies->resolveFor($message);

        // Messenger reads the last stamp of a class: a policy stamp must not override one the caller passed.
        $callerStampClasses = [];
        foreach ($stamps as $stamp) {
            $callerStampClasses[$stamp::class] = true;
        }

        foreach ($policy->getStamps($message, $mode) as $stamp) {
            if (!isset($callerStampClasses[$stamp::class])) {
                $stamps[] = $stamp;
            }
        }

        return $stamps;
    }
}
