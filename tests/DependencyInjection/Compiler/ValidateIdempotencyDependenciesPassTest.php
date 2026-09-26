<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateIdempotencyDependenciesPass;
use SomeWork\CqrsBundle\Support\IdempotencyStampDecider;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\StoreFactory;
use Symfony\Component\Messenger\Middleware\DeduplicateMiddleware;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

use function str_replace;

#[CoversClass(ValidateIdempotencyDependenciesPass::class)]
final class ValidateIdempotencyDependenciesPassTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function inactiveSettings(): iterable
    {
        yield 'parameter missing' => [null];
        yield 'disabled' => [false];
        yield 'not strictly true' => ['yes'];
    }

    #[DataProvider('inactiveSettings')]
    public function test_logs_nothing_when_idempotency_is_off(mixed $enabled): void
    {
        $container = new ContainerBuilder();
        if (null !== $enabled) {
            $container->setParameter('somework_cqrs.idempotency.enabled', $enabled);
        }

        (new ValidateIdempotencyDependenciesPass(static fn (): bool => false))->process($container);

        self::assertSame([], $container->getCompiler()->getLog());
    }

    public function test_logs_nothing_when_everything_is_in_place(): void
    {
        $container = $this->enabledContainer();
        $container->register('messenger.middleware.deduplicate_middleware', DeduplicateMiddleware::class);

        (new ValidateIdempotencyDependenciesPass(static fn (): bool => true))->process($container);

        self::assertSame([], $container->getCompiler()->getLog());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingClasses(): iterable
    {
        yield 'messenger < 7.3' => [DeduplicateStamp::class];
        yield 'no symfony/lock' => [Key::class];
    }

    #[DataProvider('missingClasses')]
    public function test_logs_missing_packages(string $missingClass): void
    {
        $container = $this->enabledContainer();

        (new ValidateIdempotencyDependenciesPass(static fn (string $class): bool => $class !== $missingClass))->process($container);

        $log = $container->getCompiler()->getLog();
        self::assertCount(1, $log);
        self::assertStringContainsString('needs symfony/messenger ^7.3 (DeduplicateStamp) and symfony/lock', $log[0]);
    }

    public function test_logs_a_missing_deduplicate_middleware(): void
    {
        $container = $this->enabledContainer();

        (new ValidateIdempotencyDependenciesPass(static fn (): bool => true))->process($container);

        $log = $container->getCompiler()->getLog();
        self::assertCount(1, $log);
        self::assertStringContainsString('deduplicate middleware is not registered', $log[0]);
        self::assertStringContainsString('"framework.lock"', $log[0]);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function lockStores(): iterable
    {
        yield 'flock (FrameworkBundle default)' => ['flock', 'releases a key as soon as the dispatch returns'];
        yield 'semaphore (FrameworkBundle default with ext-sysvsem)' => ['semaphore', 'releases a key as soon as the dispatch returns'];
        yield 'in-memory' => ['in-memory', 'only deduplicates within one process'];
        yield 'flock with a path' => ['flock:///var/lock', 'releases a key as soon as the dispatch returns'];
        yield 'PostgreSQL advisory locks' => ['postgresql+advisory://db:5432/app', 'a key stays locked while the connection lives, whatever the TTL'];
        yield 'ZooKeeper' => ['zookeeper://localhost:2181', 'ties its keys to one connection'];
        yield 'redis' => ['redis://localhost', null];
        yield 'dbal' => ['mysql://db/app', null];
    }

    #[DataProvider('lockStores')]
    public function test_warns_about_lock_stores_that_cannot_back_idempotency(string $dsn, ?string $warning): void
    {
        $container = $this->containerWithLockStore($dsn);

        (new ValidateIdempotencyDependenciesPass(static fn (): bool => true))->process($container);

        $log = $container->getCompiler()->getLog();
        if (null !== $warning) {
            self::assertCount(1, $log);
            self::assertStringContainsString($warning, $log[0]);
        } else {
            self::assertSame([], $log);
        }
    }

    public function test_a_store_from_an_environment_variable_is_checked_with_its_value_at_compile_time(): void
    {
        $container = $this->enabledContainer();
        $container->setParameter('env(CQRS_TEST_LOCK_DSN)', 'flock');
        $placeholder = $container->getParameterBag()->resolveValue('%env(CQRS_TEST_LOCK_DSN)%');
        self::assertIsString($placeholder);
        $container = $this->containerWithLockStore($placeholder, $container);

        (new ValidateIdempotencyDependenciesPass(static fn (): bool => true))->process($container);

        $log = $container->getCompiler()->getLog();
        self::assertCount(1, $log);
        self::assertStringContainsString('"flock" (the environment value when the container was compiled)', $log[0]);
    }

    public function test_hands_the_problem_to_the_stamp_decider(): void
    {
        // The decider logs it as a warning the first time a message carries an IdempotencyStamp.
        $container = $this->containerWithLockStore('flock');
        $container->register('somework_cqrs.stamp_decider.idempotency', IdempotencyStampDecider::class);

        (new ValidateIdempotencyDependenciesPass(static fn (): bool => true))->process($container);

        $problem = $container->getDefinition('somework_cqrs.stamp_decider.idempotency')->getArgument('$problem');
        self::assertIsString($problem);
        self::assertStringContainsString('releases a key as soon as the dispatch returns', $problem);
        // Escaped: the advice names "%env(LOCK_DSN)%", which must not become an environment variable.
        self::assertStringContainsString('"%%env(LOCK_DSN)%%"', $problem);
        self::assertStringContainsString(str_replace('%%', '%', $problem), $container->getCompiler()->getLog()[0]);
        self::assertTrue($container->getDefinition('somework_cqrs.stamp_decider.idempotency')->getArgument('$keysCannotBeSent'), 'An outbox dispatch with an IdempotencyStamp is refused.');
    }

    private function containerWithLockStore(string $dsn, ?ContainerBuilder $container = null): ContainerBuilder
    {
        $container ??= $this->enabledContainer();
        $container->register('messenger.middleware.deduplicate_middleware', DeduplicateMiddleware::class);
        // What FrameworkBundle registers for "framework.lock: <dsn>".
        $container->register('.lock.default.store.abc', PersistingStoreInterface::class)
            ->setFactory([StoreFactory::class, 'createStore'])
            ->setArguments([$dsn]);
        $container->setDefinition('lock.default.factory', (new ChildDefinition('lock.factory.abstract'))->replaceArgument(0, new Reference('.lock.default.store.abc')));
        $container->setAlias('lock.factory', 'lock.default.factory');

        return $container;
    }

    private function enabledContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.idempotency.enabled', true);

        return $container;
    }
}
