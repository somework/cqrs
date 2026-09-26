<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\OutboxRelayCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\OutboxTestKernel;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function dirname;

/**
 * Relays started with another APP_DEBUG (or APP_ENV) must share the lock, or they send the same rows.
 */
#[Group('database')]
#[CoversNothing]
final class OutboxRelayLockNameTest extends TestCase
{
    public function test_the_lock_name_does_not_depend_on_the_debug_mode(): void
    {
        $withDebug = self::lockName(new OutboxTestKernel('test', true));
        $withoutDebug = self::lockName(new OutboxTestKernel('test', false));

        self::assertSame($withDebug, $withoutDebug);
        self::assertSame('somework_cqrs.outbox.relay.'.dirname(__DIR__, 2).'.default.somework_cqrs_outbox', $withDebug);
    }

    private static function lockName(OutboxTestKernel $kernel): string
    {
        $kernel->boot();

        try {
            $testContainer = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $testContainer);
            $relay = $testContainer->get('somework_cqrs.outbox.relay_command');
            self::assertInstanceOf(OutboxRelayCommand::class, $relay);

            $lockName = (new \ReflectionProperty(OutboxRelayCommand::class, 'lockName'))->getValue($relay);
            self::assertIsString($lockName);

            return $lockName;
        } finally {
            $kernel->shutdown();
        }
    }
}
