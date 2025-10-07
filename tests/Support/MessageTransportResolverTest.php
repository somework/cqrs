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
