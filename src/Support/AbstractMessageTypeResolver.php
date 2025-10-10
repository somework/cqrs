<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Container\ContainerInterface;

abstract class AbstractMessageTypeResolver
{
    public function __construct(
        private readonly ContainerInterface $services,
    ) {
    }

    /**
     * @param list<string> $ignoredKeys
     */
    final protected function resolveService(object $message, array $ignoredKeys = []): mixed
    {
        $match = MessageTypeLocator::match($this->services, $message, $ignoredKeys);

        if (null !== $match) {
            return $this->assertService($match->type, $match->service);
        }

        return $this->resolveFallback($message);
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
