<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class MessageTransportResolverTest extends TestCase
{
    public function test_resolves_transports_from_class_hierarchy(): void
    {
        $resolver = new MessageTransportResolver(new ServiceLocator([
            MessageTransportResolver::DEFAULT_KEY => static fn (): array => ['default_bus'],
            MessageTransportResolverTestParentCommand::class => static fn (): string => 'async_commands',
        ]));

        $transports = $resolver->resolveFor(new MessageTransportResolverTestChildCommand());

        self::assertSame(['async_commands'], $transports);
    }

    public function test_resolves_transports_from_interface_hierarchy(): void
    {
        $resolver = new MessageTransportResolver(new ServiceLocator([
            MessageTransportResolver::DEFAULT_KEY => static fn (): array => ['default_bus'],
            MessageTransportResolverTestInterface::class => static fn (): array => ['first', 'second', 'first'],
        ]));

        $transports = $resolver->resolveFor(new MessageTransportResolverTestInterfaceCommand());

        self::assertSame(['first', 'second'], $transports);
    }

    public function test_falls_back_to_default_transports(): void
    {
        $resolver = new MessageTransportResolver(new ServiceLocator([
            MessageTransportResolver::DEFAULT_KEY => static fn (): array => ['primary', 'secondary', 'primary'],
        ]));

        $transports = $resolver->resolveFor(new MessageTransportResolverTestChildCommand());

        self::assertSame(['primary', 'secondary'], $transports);
    }

    public function test_returns_null_when_no_match_or_default_exists(): void
    {
        $resolver = new MessageTransportResolver(new ServiceLocator([]));

        self::assertNull($resolver->resolveFor(new MessageTransportResolverTestChildCommand()));
    }

    public function test_caches_static_transports_and_skips_closure_results(): void
    {
        $staticCounter = new MessageTransportResolverTestIterationCounter();
        $dynamicCounter = new MessageTransportResolverTestIterationCounter();
        $dynamicClosureCalls = 0;

        $resolver = new MessageTransportResolver(new ServiceLocator([
            MessageTransportResolverTestChildCommand::class => static fn (): \Traversable => new MessageTransportResolverTestCountingIterator(['async_commands', 'default_bus'], $staticCounter),
            MessageTransportResolver::DEFAULT_KEY => static function () use ($dynamicCounter, &$dynamicClosureCalls): \Closure {
                return static function () use ($dynamicCounter, &$dynamicClosureCalls): \Traversable {
                    ++$dynamicClosureCalls;

                    return new MessageTransportResolverTestCountingIterator(['fallback_bus'], $dynamicCounter);
                };
            },
        ]));

        $firstStaticResult = $resolver->resolveFor(new MessageTransportResolverTestChildCommand());
        $secondStaticResult = $resolver->resolveFor(new MessageTransportResolverTestChildCommand());

        self::assertSame(['async_commands', 'default_bus'], $firstStaticResult);
        self::assertSame($firstStaticResult, $secondStaticResult);
        self::assertSame(1, $staticCounter->count);

        $firstDynamicResult = $resolver->resolveFor(new MessageTransportResolverTestUnmatchedCommand());
        $secondDynamicResult = $resolver->resolveFor(new MessageTransportResolverTestUnmatchedCommand());

        self::assertSame(['fallback_bus'], $firstDynamicResult);
        self::assertSame(['fallback_bus'], $secondDynamicResult);
        self::assertSame(2, $dynamicCounter->count);
        self::assertSame(2, $dynamicClosureCalls);
    }
}

class MessageTransportResolverTestParentCommand
{
}

class MessageTransportResolverTestChildCommand extends MessageTransportResolverTestParentCommand
{
}

interface MessageTransportResolverTestInterface
{
}

class MessageTransportResolverTestInterfaceCommand implements MessageTransportResolverTestInterface
{
}

class MessageTransportResolverTestUnmatchedCommand
{
}

final class MessageTransportResolverTestIterationCounter
{
    public int $count = 0;
}

final class MessageTransportResolverTestCountingIterator implements \IteratorAggregate
{
    /**
     * @param list<string> $values
     */
    public function __construct(
        private readonly array $values,
        private readonly MessageTransportResolverTestIterationCounter $counter,
    ) {
    }

    public function getIterator(): \Traversable
    {
        ++$this->counter->count;

        yield from $this->values;
    }
}
