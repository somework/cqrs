<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

use function array_key_exists;
use function implode;

/** @internal */
abstract class AbstractMessageTypeResolver
{
    public function __construct(
        private readonly ContainerInterface $services,
        protected readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Resolved service per message class and ignored keys: the resolution only depends on the
     * class, and the locator is immutable (a service defined as not shared is reused as well).
     *
     * @var array<string, mixed>
     */
    private array $resolved = [];

    /**
     * @param list<string> $ignoredKeys
     */
    final protected function resolveService(object $message, array $ignoredKeys = []): mixed
    {
        $cacheKey = [] === $ignoredKeys ? $message::class : $message::class."\0".implode("\0", $ignoredKeys);
        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }

        $match = MessageTypeLocator::match($this->services, $message, $ignoredKeys);

        if (null !== $match) {
            $this->logger?->debug('Resolved {resolver} for {message} via {match_type}', [
                'message' => $message::class,
                'match_type' => $match->type,
                'resolver' => static::class,
            ]);

            return $this->resolved[$cacheKey] = $this->assertService($match->type, $match->service);
        }

        $this->logger?->debug('Resolved {resolver} for {message} via fallback', [
            'message' => $message::class,
            'resolver' => static::class,
        ]);

        return $this->resolved[$cacheKey] = $this->resolveFallback($message);
    }

    final protected function hasService(string $key): bool
    {
        return $this->services->has($key);
    }

    final protected function getService(string $key): mixed
    {
        return $this->assertService($key, $this->services->get($key));
    }

    /**
     * @param list<string> $keys
     */
    final protected function resolveFirstAvailable(array $keys): mixed
    {
        foreach ($keys as $key) {
            if (!$this->hasService($key)) {
                continue;
            }

            return $this->getService($key);
        }

        return null;
    }

    abstract protected function assertService(string $key, mixed $service): mixed;

    abstract protected function resolveFallback(object $message): mixed;
}
