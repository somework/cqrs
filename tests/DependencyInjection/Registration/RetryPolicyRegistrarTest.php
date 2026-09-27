<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Registration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\DependencyInjection\Registration\ContainerHelper;
use SomeWork\CqrsBundle\DependencyInjection\Registration\RetryPolicyRegistrar;
use SomeWork\CqrsBundle\Policy\ExponentialBackoffRetryPolicy;
use SomeWork\CqrsBundle\Policy\NullRetryPolicy;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(RetryPolicyRegistrar::class)]
final class RetryPolicyRegistrarTest extends TestCase
{
    public function test_a_type_without_its_own_default_uses_the_global_one(): void
    {
        $container = new ContainerBuilder();

        (new RetryPolicyRegistrar(new ContainerHelper()))->register($container, [
            'default' => ExponentialBackoffRetryPolicy::class,
            'command' => ['default' => null, 'map' => []],
            'query' => ['default' => NullRetryPolicy::class, 'map' => []],
            'event' => ['default' => null, 'map' => []],
        ]);

        self::assertSame(ExponentialBackoffRetryPolicy::class, (string) $container->getAlias('somework_cqrs.retry.command'));
        self::assertSame(NullRetryPolicy::class, (string) $container->getAlias('somework_cqrs.retry.query'));
        self::assertSame(ExponentialBackoffRetryPolicy::class, (string) $container->getAlias('somework_cqrs.retry.event'));
        self::assertSame(
            [['retry_policies.default', ExponentialBackoffRetryPolicy::class, RetryPolicy::class], ['retry_policies.query.default', NullRetryPolicy::class, RetryPolicy::class]],
            $container->getParameter(ContainerHelper::CONFIGURED_SERVICES),
        );
    }
}
