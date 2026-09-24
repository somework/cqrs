<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\DeduplicationLockReleasePass;
use SomeWork\CqrsBundle\Messenger\DeduplicationLockReleaseMiddleware;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_map;
use function array_values;

#[CoversClass(DeduplicationLockReleasePass::class)]
final class DeduplicationLockReleasePassTest extends TestCase
{
    public function test_registers_the_middleware_right_after_deduplicate_middleware(): void
    {
        $container = $this->createContainer();

        (new DeduplicationLockReleasePass())->process($container);

        self::assertSame(DeduplicationLockReleaseMiddleware::class, $container->getDefinition(DeduplicationLockReleasePass::MIDDLEWARE_ID)->getClass());
        self::assertSame(
            ['messenger.middleware.deduplicate_middleware', DeduplicationLockReleasePass::MIDDLEWARE_ID, 'messenger.bus.default.middleware.handle_message'],
            $this->middlewareIds($container),
        );
    }

    public function test_does_nothing_when_the_idempotency_bridge_is_inactive(): void
    {
        $container = $this->createContainer();
        $container->removeDefinition('somework_cqrs.stamp_decider.idempotency');

        (new DeduplicationLockReleasePass())->process($container);

        self::assertFalse($container->hasDefinition(DeduplicationLockReleasePass::MIDDLEWARE_ID));
    }

    public function test_does_nothing_without_a_lock_factory(): void
    {
        $container = $this->createContainer();
        $container->removeDefinition('lock.factory');

        (new DeduplicationLockReleasePass())->process($container);

        self::assertFalse($container->hasDefinition(DeduplicationLockReleasePass::MIDDLEWARE_ID));
    }

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.default_bus', 'messenger.bus.default');
        $container->register('somework_cqrs.stamp_decider.idempotency');
        $container->register('lock.factory');
        $container->setDefinition('messenger.bus.default', (new Definition())->setArgument(0, new IteratorArgument([
            new Reference('messenger.middleware.deduplicate_middleware'),
            new Reference('messenger.bus.default.middleware.handle_message'),
        ])));

        return $container;
    }

    /**
     * @return list<string>
     */
    private function middlewareIds(ContainerBuilder $container): array
    {
        $argument = $container->getDefinition('messenger.bus.default')->getArgument(0);
        self::assertInstanceOf(IteratorArgument::class, $argument);

        return array_values(array_map(static fn (mixed $reference): string => (string) $reference, $argument->getValues()));
    }
}
