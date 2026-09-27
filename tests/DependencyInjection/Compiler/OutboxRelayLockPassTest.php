<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\OutboxRelayCommand;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OutboxRelayLockPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(OutboxRelayLockPass::class)]
final class OutboxRelayLockPassTest extends TestCase
{
    public function test_scopes_the_lock_with_the_cache_prefix_seed(): void
    {
        $container = $this->container();
        $container->setParameter('kernel.project_dir', '/srv/releases/42');
        $container->setParameter('cache.prefix.seed', 'shop.example.com');

        (new OutboxRelayLockPass())->process($container);

        self::assertSame(
            'somework_cqrs.outbox.relay.shop.example.com.default.somework_cqrs_outbox',
            $container->getParameterBag()->resolveValue($container->getDefinition(OutboxRelayLockPass::RELAY_ID)->getArgument('$lockName')),
        );
    }

    public function test_falls_back_to_the_project_directory(): void
    {
        $container = $this->container();
        $container->setParameter('kernel.project_dir', '/srv/app');

        (new OutboxRelayLockPass())->process($container);

        self::assertSame(
            'somework_cqrs.outbox.relay./srv/app.default.somework_cqrs_outbox',
            $container->getParameterBag()->resolveValue($container->getDefinition(OutboxRelayLockPass::RELAY_ID)->getArgument('$lockName')),
        );
    }

    public function test_symfony_default_seed_is_replaced_by_the_project_directory(): void
    {
        // The default seed contains the container class, which differs per APP_ENV and APP_DEBUG.
        $container = $this->container();
        $container->setParameter('kernel.project_dir', '/srv/app');
        $container->setParameter('kernel.container_class', 'App_KernelProdContainer');
        $container->setParameter('cache.prefix.seed', '_%kernel.project_dir%.%kernel.container_class%');

        (new OutboxRelayLockPass())->process($container);

        self::assertSame(
            'somework_cqrs.outbox.relay./srv/app.default.somework_cqrs_outbox',
            $container->getParameterBag()->resolveValue($container->getDefinition(OutboxRelayLockPass::RELAY_ID)->getArgument('$lockName')),
        );
    }

    public function test_the_resolved_default_seed_is_replaced_by_the_project_directory(): void
    {
        // FrameworkBundle resolves the parameters of its configuration.
        $container = $this->container();
        $container->setParameter('kernel.project_dir', '/srv/app');
        $container->setParameter('kernel.container_class', 'App_KernelProdContainer');
        $container->setParameter('cache.prefix.seed', '_/srv/app.App_KernelProdContainer');

        (new OutboxRelayLockPass())->process($container);

        self::assertSame(
            'somework_cqrs.outbox.relay./srv/app.default.somework_cqrs_outbox',
            $container->getParameterBag()->resolveValue($container->getDefinition(OutboxRelayLockPass::RELAY_ID)->getArgument('$lockName')),
        );
    }

    public function test_does_nothing_without_the_outbox(): void
    {
        $container = new ContainerBuilder();

        (new OutboxRelayLockPass())->process($container);

        self::assertFalse($container->hasDefinition(OutboxRelayLockPass::RELAY_ID));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(OutboxRelayLockPass::RELAY_ID, OutboxRelayCommand::class)
            ->setArgument('$lockName', 'default.somework_cqrs_outbox');

        return $container;
    }
}
