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
final class RetryPolicyResolver extends AbstractMessageTypeResolver
{
    public function __construct(
        private readonly RetryPolicy $defaultPolicy,
        ContainerInterface $policies,
    ) {
        parent::__construct($policies);
    }

    public static function withoutOverrides(?RetryPolicy $defaultPolicy = null): self
    {
        return new self($defaultPolicy ?? new NullRetryPolicy(), new ServiceLocator([]));
    }

    public function resolveFor(object $message): RetryPolicy
    {
        /** @var RetryPolicy $policy */
        $policy = $this->resolveService($message);

        return $policy;
    }

    protected function assertService(string $type, mixed $service): RetryPolicy
    {
        if (!$service instanceof RetryPolicy) {
            throw new \LogicException(sprintf('Retry policy override for "%s" must implement %s.', $type, RetryPolicy::class));
        }

        return $service;
    }

    protected function resolveFallback(object $message): RetryPolicy
    {
        return $this->defaultPolicy;
    }
}
