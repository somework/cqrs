<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Closure;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\Key;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

use function class_exists;
use function is_string;
use function preg_match;
use function sprintf;

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

            return;
        }

        $store = self::lockStoreDsn($container);
        if (null !== $store && 1 === preg_match('/^(flock|semaphore|in-memory)(:|$)/', $store)) {
            $container->log($this, sprintf('Idempotency is enabled but the lock store "%s" only lives in one process or host: the "flock" and "semaphore" stores release a key as soon as the dispatch returns and cannot be sent to async transports. Configure a shared store that keeps keys until their TTL, e.g. framework.lock: "%%env(LOCK_DSN)%%" with Redis or a database.', $store));
        }
    }

    /**
     * DSN of the store behind "lock.factory" as configured in framework.lock, when it is a literal.
     */
    private static function lockStoreDsn(ContainerBuilder $container): ?string
    {
        if (!$container->has('lock.factory')) {
            return null;
        }

        $factory = $container->findDefinition('lock.factory');
        $storeReference = $factory->getArguments()['index_0'] ?? $factory->getArguments()[0] ?? null;
        if (!$storeReference instanceof Reference || !$container->has((string) $storeReference)) {
            return null;
        }

        $dsn = $container->findDefinition((string) $storeReference)->getArguments()[0] ?? null;
        if (!is_string($dsn)) {
            return null;
        }

        // Environment placeholders are only known at runtime.
        $resolved = $container->resolveEnvPlaceholders($dsn, null, $usedEnvs);

        return [] === ($usedEnvs ?? []) && is_string($resolved) ? $resolved : null;
    }
}
