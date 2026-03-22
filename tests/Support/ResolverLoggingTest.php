<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use SomeWork\CqrsBundle\Support\NullRetryPolicy;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryAwareMessage;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function is_string;

final class ResolverLoggingTest extends TestCase
{
    public function test_resolver_logs_exact_match_with_logger(): void
    {
        $override = $this->createMock(RetryPolicy::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('debug')
            ->with(
                self::callback(static fn (mixed $v): bool => is_string($v)),
                self::callback(static fn (array $context): bool => isset($context['message']) && isset($context['match_type']))
            );

        $resolver = new RetryPolicyResolver(
            new NullRetryPolicy(),
            new ServiceLocator([
                TaskCreatedEvent::class => static fn (): RetryPolicy => $override,
            ]),
            $logger,
        );

        $policy = $resolver->resolveFor(new TaskCreatedEvent('1'));

        self::assertSame($override, $policy);
    }

    public function test_resolver_logs_interface_match_with_logger(): void
    {
        $interfacePolicy = $this->createMock(RetryPolicy::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('debug')
            ->with(
                self::callback(static fn (mixed $v): bool => is_string($v)),
                self::callback(static fn (array $context): bool => isset($context['message']) && isset($context['match_type']))
            );

        $resolver = new RetryPolicyResolver(
            new NullRetryPolicy(),
            new ServiceLocator([
                RetryAwareMessage::class => static fn (): RetryPolicy => $interfacePolicy,
            ]),
            $logger,
        );

        $policy = $resolver->resolveFor(new CreateTaskCommand('1', 'Test'));

        self::assertSame($interfacePolicy, $policy);
    }

    public function test_resolver_logs_fallback_with_logger(): void
    {
        $default = $this->createMock(RetryPolicy::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('debug')
            ->with(
                self::callback(static fn (mixed $v): bool => is_string($v)),
                self::callback(static fn (array $context): bool => isset($context['message']) && isset($context['resolver']))
            );

        $resolver = new RetryPolicyResolver(
            $default,
            new ServiceLocator([]),
            $logger,
        );

        $policy = $resolver->resolveFor(new CreateTaskCommand('1', 'Test'));

        self::assertSame($default, $policy);
    }

    public function test_resolver_works_without_logger(): void
    {
        $default = $this->createMock(RetryPolicy::class);
        $resolver = new RetryPolicyResolver($default, new ServiceLocator([]));

        $policy = $resolver->resolveFor(new CreateTaskCommand('1', 'Test'));

        self::assertSame($default, $policy);
    }

    public function test_resolver_exact_match_log_context_has_exact_match_type(): void
    {
        $override = $this->createMock(RetryPolicy::class);

        $logContexts = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('debug')
            ->willReturnCallback(static function (string $message, array $context) use (&$logContexts): void {
                $logContexts[] = $context;
            });

        $resolver = new RetryPolicyResolver(
            new NullRetryPolicy(),
            new ServiceLocator([
                TaskCreatedEvent::class => static fn (): RetryPolicy => $override,
            ]),
            $logger,
        );

        $resolver->resolveFor(new TaskCreatedEvent('1'));

        $matchContext = $logContexts[0];
        self::assertSame(TaskCreatedEvent::class, $matchContext['match_type']);
    }

    public function test_resolver_interface_match_log_context_has_interface_match_type(): void
    {
        $interfacePolicy = $this->createMock(RetryPolicy::class);

        $logContexts = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('debug')
            ->willReturnCallback(static function (string $message, array $context) use (&$logContexts): void {
                $logContexts[] = $context;
            });

        $resolver = new RetryPolicyResolver(
            new NullRetryPolicy(),
            new ServiceLocator([
                RetryAwareMessage::class => static fn (): RetryPolicy => $interfacePolicy,
            ]),
            $logger,
        );

        $resolver->resolveFor(new CreateTaskCommand('1', 'Test'));

        $matchContext = $logContexts[0];
        self::assertSame(RetryAwareMessage::class, $matchContext['match_type']);
    }

    public function test_resolver_fallback_log_context_has_resolver_class(): void
    {
        $default = $this->createMock(RetryPolicy::class);

        $logContexts = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('debug')
            ->willReturnCallback(static function (string $message, array $context) use (&$logContexts): void {
                $logContexts[] = $context;
            });

        $resolver = new RetryPolicyResolver(
            $default,
            new ServiceLocator([]),
            $logger,
        );

        $resolver->resolveFor(new CreateTaskCommand('1', 'Test'));

        $matchContext = $logContexts[0];
        self::assertSame(RetryPolicyResolver::class, $matchContext['resolver']);
        self::assertArrayNotHasKey('match_type', $matchContext);
    }

    public function test_transport_resolver_logs_exact_match(): void
    {
        $logContexts = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('debug')
            ->willReturnCallback(static function (string $message, array $context) use (&$logContexts): void {
                $logContexts[] = $context;
            });

        $resolver = new MessageTransportResolver(
            new ServiceLocator([
                TaskCreatedEvent::class => static fn (): array => ['async'],
            ]),
            $logger,
        );

        $transports = $resolver->resolveFor(new TaskCreatedEvent('1'));

        self::assertSame(['async'], $transports);
        self::assertSame(TaskCreatedEvent::class, $logContexts[0]['match_type']);
    }

    public function test_transport_resolver_logs_fallback(): void
    {
        $logMessages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('debug')
            ->willReturnCallback(static function (string $message) use (&$logMessages): void {
                $logMessages[] = $message;
            });

        $resolver = new MessageTransportResolver(
            new ServiceLocator([
                MessageTransportResolver::DEFAULT_KEY => static fn (): array => ['default-transport'],
            ]),
            $logger,
        );

        $transports = $resolver->resolveFor(new CreateTaskCommand('1', 'Test'));

        self::assertSame(['default-transport'], $transports);
        self::assertStringContainsString('fallback', $logMessages[0]);
    }

    public function test_transport_resolver_logs_no_transport_found(): void
    {
        $logMessages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('debug')
            ->willReturnCallback(static function (string $message) use (&$logMessages): void {
                $logMessages[] = $message;
            });

        $resolver = new MessageTransportResolver(
            new ServiceLocator([]),
            $logger,
        );

        $transports = $resolver->resolveFor(new CreateTaskCommand('1', 'Test'));

        self::assertNull($transports);
        self::assertStringContainsString('No transport resolved', $logMessages[0]);
    }

    public function test_transport_resolver_works_without_logger(): void
    {
        $resolver = new MessageTransportResolver(
            new ServiceLocator([
                TaskCreatedEvent::class => static fn (): array => ['async'],
            ]),
        );

        $transports = $resolver->resolveFor(new TaskCreatedEvent('1'));

        self::assertSame(['async'], $transports);
    }
}
