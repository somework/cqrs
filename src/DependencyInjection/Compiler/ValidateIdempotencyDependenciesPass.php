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

        [$store, $fromEnvironment] = self::lockStoreDsn($container) ?? [null, false];
        if (null === $store) {
            return;
        }

        $origin = $fromEnvironment ? sprintf('"%s" (the environment value when the container was compiled)', $store) : sprintf('"%s"', $store);

        if (1 === preg_match('/^(flock|semaphore|in-memory)(:|$)/', $store)) {
            $container->log($this, sprintf('Idempotency is enabled but the lock store %s only lives in one process or host: it does not deduplicate across servers, and its keys cannot be sent with async messages. Configure a shared store that keeps keys until their TTL, e.g. framework.lock: "%%env(LOCK_DSN)%%" with Redis or a database.', $origin));
        } elseif (1 === preg_match('/^((pgsql|postgres|postgresql)\+advisory|zookeeper):/', $store)) {
            $container->log($this, sprintf('Idempotency is enabled but the lock store %s ties its keys to one connection: they cannot be sent with async messages, so asynchronous dispatches with an IdempotencyStamp fail. Use Redis, Memcached or a PDO/DBAL store for idempotency.', $origin));
        }
    }

    /**
     * DSN of the store behind "lock.factory" as configured in framework.lock, and whether it came
     * from environment variables (resolved with the values of the compiling process).
     *
     * @return array{string, bool}|null
     */
    private static function lockStoreDsn(ContainerBuilder $container): ?array
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

        $container->resolveEnvPlaceholders($dsn, null, $usedEnvs);
        if ([] === ($usedEnvs ?? [])) {
            return [$dsn, false];
        }

        // Only a hint: the runtime environment may differ (the Flex recipe defaults LOCK_DSN to "flock").
        try {
            $resolved = $container->resolveEnvPlaceholders($dsn, true);
        } catch (\Throwable) {
            return null;
        }

        return is_string($resolved) ? [$resolved, true] : null;
    }
}
