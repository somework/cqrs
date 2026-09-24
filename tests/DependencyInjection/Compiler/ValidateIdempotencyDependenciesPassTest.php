<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateIdempotencyDependenciesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Lock\Key;
use Symfony\Component\Messenger\Middleware\DeduplicateMiddleware;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

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

    private function enabledContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.idempotency.enabled', true);

        return $container;
    }
}
