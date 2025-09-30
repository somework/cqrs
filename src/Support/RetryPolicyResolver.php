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
        $messageClass = $message::class;

        foreach ($this->classHierarchy($messageClass) as $type) {
            if ($this->policies->has($type)) {
                return $this->assertPolicy($type, $this->policies->get($type));
            }
        }

        foreach ($this->interfaces($messageClass) as $interface) {
            if ($this->policies->has($interface)) {
                return $this->assertPolicy($interface, $this->policies->get($interface));
            }
        }

        return $this->defaultPolicy;
    }

    /**
     * @return iterable<class-string>
     */
    private function classHierarchy(string $class): iterable
    {
        for ($type = $class; false !== $type; $type = get_parent_class($type)) {
            yield $type;
        }
    }

    /**
     * @return iterable<class-string>
     */
    private function interfaces(string $class): iterable
    {
        $seen = [];

        foreach ($this->classHierarchy($class) as $type) {
            foreach (class_implements($type, false) as $interface) {
                if (isset($seen[$interface])) {
                    continue;
                }

                $seen[$interface] = true;

                yield $interface;
            }
        }
    }

    private function assertPolicy(string $type, mixed $service): RetryPolicy
    {
        if (!$service instanceof RetryPolicy) {
            throw new \LogicException(sprintf('Retry policy override for "%s" must implement %s.', $type, RetryPolicy::class));
        }

        return $service;
    }
}
