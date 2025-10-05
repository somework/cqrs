<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Container\ContainerInterface;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function sprintf;

/**
 * Resolves the RetryPolicy to apply for a given message class.
 */
final class RetryPolicyResolver
{
    public function __construct(
        private readonly RetryPolicy $defaultPolicy,
        private readonly ContainerInterface $policies,
    ) {
    }

    public static function withoutOverrides(?RetryPolicy $defaultPolicy = null): self
    {
        return new self($defaultPolicy ?? new NullRetryPolicy(), new ServiceLocator([]));
    }

    public function resolveFor(object $message): RetryPolicy
    {
        $match = MessageTypeLocator::match($this->policies, $message);

        if (null !== $match) {
            return $this->assertPolicy($match->type, $match->service);
        }

        return $this->defaultPolicy;
    }

    private function assertPolicy(string $type, mixed $service): RetryPolicy
    {
        if (!$service instanceof RetryPolicy) {
            throw new \LogicException(sprintf('Retry policy override for "%s" must implement %s.', $type, RetryPolicy::class));
        }

        return $service;
    }
}
