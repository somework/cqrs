<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use LogicException;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\MessageMetadataProvider;
use SomeWork\CqrsBundle\Support\MessageMetadataProviderResolver;
use SomeWork\CqrsBundle\Support\RandomCorrelationMetadataProvider;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class MessageMetadataProviderResolverTest extends TestCase
{
    public function test_resolves_provider_from_class_hierarchy(): void
    {
        $provider = $this->createMock(MessageMetadataProvider::class);

        $resolver = new MessageMetadataProviderResolver(new ServiceLocator([
            MessageMetadataProviderResolver::GLOBAL_DEFAULT_KEY => static fn (): MessageMetadataProvider => $this->createMock(MessageMetadataProvider::class),
            MessageMetadataProviderResolver::TYPE_DEFAULT_KEY => static fn (): MessageMetadataProvider => $this->createMock(MessageMetadataProvider::class),
            MessageMetadataProviderResolverTestParentCommand::class => static fn (): MessageMetadataProvider => $provider,
        ]));

        $resolved = $resolver->resolveFor(new MessageMetadataProviderResolverTestChildCommand());

        self::assertSame($provider, $resolved);
    }

    public function test_resolves_provider_from_interface_hierarchy(): void
    {
        $provider = $this->createMock(MessageMetadataProvider::class);

        $resolver = new MessageMetadataProviderResolver(new ServiceLocator([
            MessageMetadataProviderResolver::GLOBAL_DEFAULT_KEY => static fn (): MessageMetadataProvider => $this->createMock(MessageMetadataProvider::class),
            MessageMetadataProviderResolver::TYPE_DEFAULT_KEY => static fn (): MessageMetadataProvider => $this->createMock(MessageMetadataProvider::class),
            MessageMetadataProviderResolverTestInterface::class => static fn (): MessageMetadataProvider => $provider,
        ]));

        $resolved = $resolver->resolveFor(new MessageMetadataProviderResolverTestInterfaceCommand());

        self::assertSame($provider, $resolved);
    }

    public function test_uses_type_default_when_message_not_overridden(): void
    {
        $typeDefault = $this->createMock(MessageMetadataProvider::class);

        $resolver = new MessageMetadataProviderResolver(new ServiceLocator([
            MessageMetadataProviderResolver::GLOBAL_DEFAULT_KEY => static fn (): MessageMetadataProvider => new RandomCorrelationMetadataProvider(),
            MessageMetadataProviderResolver::TYPE_DEFAULT_KEY => static fn (): MessageMetadataProvider => $typeDefault,
        ]));

        $resolved = $resolver->resolveFor(new MessageMetadataProviderResolverTestChildCommand());

        self::assertSame($typeDefault, $resolved);
    }

    public function test_falls_back_to_global_default_when_type_default_missing(): void
    {
        $globalDefault = $this->createMock(MessageMetadataProvider::class);

        $resolver = new MessageMetadataProviderResolver(new ServiceLocator([
            MessageMetadataProviderResolver::GLOBAL_DEFAULT_KEY => static fn (): MessageMetadataProvider => $globalDefault,
        ]));

        $resolved = $resolver->resolveFor(new MessageMetadataProviderResolverTestChildCommand());

        self::assertSame($globalDefault, $resolved);
    }

    public function test_requires_global_default_provider(): void
    {
        $resolver = new MessageMetadataProviderResolver(new ServiceLocator([]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Metadata provider resolver must be initialised with a global default provider.');

        $resolver->resolveFor(new MessageMetadataProviderResolverTestChildCommand());
    }
}

class MessageMetadataProviderResolverTestParentCommand implements Command
{
}

class MessageMetadataProviderResolverTestChildCommand extends MessageMetadataProviderResolverTestParentCommand
{
}

interface MessageMetadataProviderResolverTestInterface extends Command
{
}

class MessageMetadataProviderResolverTestInterfaceCommand implements MessageMetadataProviderResolverTestInterface
{
}
