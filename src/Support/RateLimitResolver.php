<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

use function get_debug_type;
use function sprintf;

/**
 * Resolves the RateLimiterFactory to apply for a given message class.
 *
 * @internal
 */
final class RateLimitResolver extends AbstractMessageTypeResolver
{
    /** Key of the limiter of the message type (the type default, else the global one). */
    public const DEFAULT_KEY = '__somework_cqrs_rate_limit_default';

    public function __construct(
        ContainerInterface $limiters,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($limiters, $logger);
    }

    /**
     * @return RateLimiterFactory|RateLimiterFactoryInterface|null null when no limiter is mapped
     */
    public function resolveFor(object $message): ?object
    {
        /** @var RateLimiterFactory|RateLimiterFactoryInterface|null $factory */
        $factory = $this->resolveService($message, [self::DEFAULT_KEY]);

        return $factory;
    }

    /**
     * Accepts RateLimiterFactory and, on symfony/rate-limiter 7.3+, any RateLimiterFactoryInterface
     * (e.g. compound limiters).
     */
    protected function assertService(string $key, mixed $service): object
    {
        if (!$service instanceof RateLimiterFactory && !$service instanceof RateLimiterFactoryInterface) {
            throw new \LogicException(sprintf('Rate limiter for "%s" must be an instance of %s, got %s.', $key, RateLimiterFactory::class, get_debug_type($service)));
        }

        return $service;
    }

    protected function resolveFallback(object $message): ?object
    {
        return $this->hasService(self::DEFAULT_KEY) ? $this->getService(self::DEFAULT_KEY) : null;
    }
}
