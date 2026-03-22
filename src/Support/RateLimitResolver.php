<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

use function get_debug_type;
use function sprintf;

/**
 * Resolves the RateLimiterFactory to apply for a given message class.
 *
 * @internal
 */
final class RateLimitResolver extends AbstractMessageTypeResolver
{
    public function __construct(
        ContainerInterface $limiters,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($limiters, $logger);
    }

    public function resolveFor(object $message): ?RateLimiterFactory
    {
        /* @var ?RateLimiterFactory */
        return $this->resolveService($message);
    }

    protected function assertService(string $key, mixed $service): RateLimiterFactory
    {
        if (!$service instanceof RateLimiterFactory) {
            throw new \LogicException(sprintf('Rate limiter for "%s" must be an instance of %s, got %s.', $key, RateLimiterFactory::class, get_debug_type($service)));
        }

        return $service;
    }

    protected function resolveFallback(object $message): ?RateLimiterFactory
    {
        return null;
    }
}
