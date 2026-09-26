<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Container\ContainerInterface;
use SomeWork\CqrsBundle\Contract\QueryBusInterface;
use SomeWork\CqrsBundle\Exception\RateLimitExceededException;
use SomeWork\CqrsBundle\Policy\ExponentialBackoffRetryPolicy;
use SomeWork\CqrsBundle\Policy\NullRetryPolicy;
use SomeWork\CqrsBundle\Retry\CqrsRetryStrategy;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusDecider;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\OverridesTestKernel;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\GenerateReportCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ListTasksQuery;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

use function sprintf;

/**
 * Per-message overrides must resolve to the configured services in a compiled container
 * (service locators built by the registrars must return services, not closures).
 */
#[CoversNothing]
final class ConfigurationOverridesTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return OverridesTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    public function test_message_specific_retry_policy_is_resolved(): void
    {
        $resolver = self::getContainer()->get('somework_cqrs.retry.command_resolver');
        self::assertInstanceOf(RetryPolicyResolver::class, $resolver);

        self::assertInstanceOf(ExponentialBackoffRetryPolicy::class, $resolver->resolveFor(new CreateTaskCommand('1', 'x')));
        self::assertInstanceOf(NullRetryPolicy::class, $resolver->resolveFor(new GenerateReportCommand('1')));
    }

    public function test_message_specific_dispatch_after_current_bus_toggle_is_honoured(): void
    {
        $decider = self::getContainer()->get('somework_cqrs.dispatch_after_current_bus_decider');
        self::assertInstanceOf(DispatchAfterCurrentBusDecider::class, $decider);

        self::assertFalse($decider->shouldDefer(new CreateTaskCommand('1', 'x')));
        self::assertTrue($decider->shouldDefer(new GenerateReportCommand('1')));
    }

    public function test_mapped_rate_limiter_throttles_dispatch(): void
    {
        $queryBus = self::getContainer()->get(QueryBusInterface::class);
        self::assertInstanceOf(QueryBusInterface::class, $queryBus);

        self::assertSame(['task-1', 'task-2'], $queryBus->ask(new ListTasksQuery()));

        $this->expectException(RateLimitExceededException::class);
        $queryBus->ask(new ListTasksQuery());
    }

    public function test_mapped_transport_uses_the_cqrs_retry_strategy(): void
    {
        $locator = self::getContainer()->get('messenger.retry_strategy_locator');
        self::assertInstanceOf(ContainerInterface::class, $locator);

        $strategy = $locator->get('async');
        self::assertInstanceOf(CqrsRetryStrategy::class, $strategy);

        // ExponentialBackoffRetryPolicy (mapped for CreateTaskCommand): 3 retries, 1s initial delay, x2.
        $envelope = new Envelope(new CreateTaskCommand('1', 'x'), [new RedeliveryStamp(1)]);
        self::assertTrue($strategy->isRetryable($envelope));
        self::assertSame(2000, $strategy->getWaitingTime($envelope));
        self::assertFalse($strategy->isRetryable(new Envelope(new CreateTaskCommand('1', 'x'), [new RedeliveryStamp(3)])));
    }

    public function test_health_command_checks_private_handlers_and_transports(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $tester = new CommandTester($application->find('somework:cqrs:health'));
        $tester->execute([]);

        $display = $tester->getDisplay(true);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $display);
        self::assertStringContainsString(sprintf('Handler "%s" is resolvable', CreateTaskHandler::class), $display);
        self::assertStringContainsString('Transport "async" can be created (the connection is not tested)', $display);
        self::assertStringNotContainsString('CRITICAL', $display);
    }
}
