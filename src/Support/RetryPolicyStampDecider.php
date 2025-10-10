<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Adds retry policy stamps for supported messages.
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
     * @param list<StampInterface> $stamps
     *
     * @return list<StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        if (!$message instanceof $this->messageType) {
            return $stamps;
        }

        $policy = $this->retryPolicies->resolveFor($message);

        return [...$stamps, ...$policy->getStamps($message, $mode)];
    }
}
