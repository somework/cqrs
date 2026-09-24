<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Closure;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Lock\Key;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

use function class_exists;

/**
 * Explains in the container compilation log why IdempotencyStamp would not deduplicate messages.
 *
 * Idempotency is enabled by default, so missing pieces are reported instead of failing the build.
 *
 * @internal
 */
final class ValidateIdempotencyDependenciesPass implements CompilerPassInterface
{
    private const DEDUPLICATE_MIDDLEWARE = 'messenger.middleware.deduplicate_middleware';

    /** @var Closure(string): bool */
    private readonly Closure $classExists;

    /**
     * @param (Closure(string): bool)|null $classExists Detects optional dependencies; injectable for tests
     */
    public function __construct(?Closure $classExists = null)
    {
        $this->classExists = $classExists ?? static fn (string $class): bool => class_exists($class);
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('somework_cqrs.idempotency.enabled')
            || true !== $container->getParameter('somework_cqrs.idempotency.enabled')) {
            return;
        }

        if (!($this->classExists)(DeduplicateStamp::class) || !($this->classExists)(Key::class)) {
            $container->log($this, 'Idempotency is enabled but needs symfony/messenger ^7.3 (DeduplicateStamp) and symfony/lock; IdempotencyStamp is ignored until both are installed.');

            return;
        }

        if (!$container->hasDefinition(self::DEDUPLICATE_MIDDLEWARE)) {
            $container->log($this, 'Idempotency is enabled but Messenger\'s deduplicate middleware is not registered, so DeduplicateStamp is not enforced. Enable the lock component ("framework.lock").');
        }
    }
}
