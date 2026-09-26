<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\MessageMetadataProvider;
use SomeWork\CqrsBundle\Support\MessageMetadataProviderResolver;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(MessageMetadataProviderResolver::class)]
final class MessageMetadataProviderResolverTest extends TestCase
{
    public function test_resolves_provider_from_class_hierarchy(): void
    {
        $provider = $this->createMock(MessageMetadataProvider::class);

        $globalDefault = $this->createMock(MessageMetadataProvider::class);
        $typeDefault = $this->createMock(MessageMetadataProvider::class);

        $resolver = new MessageMetadataProviderResolver(new ServiceLocator([
            MessageMetadataProviderResolver::DEFAULT_KEY => static fn (): MessageMetadataProvider => $typeDefault,
            MessageMetadataProviderResolverTestParentCommand::class => static fn (): MessageMetadataProvider => $provider,
        ]));

        $resolved = $resolver->resolveFor(new MessageMetadataProviderResolverTestChildCommand());

        self::assertSame($provider, $resolved);
    }

    public function test_resolves_provider_from_interface_hierarchy(): void
    {
        $provider = $this->createMock(MessageMetadataProvider::class);
        $globalDefault = $this->createMock(MessageMetadataProvider::class);
        $typeDefault = $this->createMock(MessageMetadataProvider::class);

        $resolver = new MessageMetadataProviderResolver(new ServiceLocator([
            MessageMetadataProviderResolver::DEFAULT_KEY => static fn (): MessageMetadataProvider => $typeDefault,
            MessageMetadataProviderResolverTestInterface::class => static fn (): MessageMetadataProvider => $provider,
        ]));

        $resolved = $resolver->resolveFor(new MessageMetadataProviderResolverTestInterfaceCommand());

        self::assertSame($provider, $resolved);
    }

    public function test_uses_type_default_when_message_not_overridden(): void
    {
        $typeDefault = $this->createMock(MessageMetadataProvider::class);

        $resolver = new MessageMetadataProviderResolver(new ServiceLocator([
            MessageMetadataProviderResolver::DEFAULT_KEY => static fn (): MessageMetadataProvider => $typeDefault,
        ]));

        $resolved = $resolver->resolveFor(new MessageMetadataProviderResolverTestChildCommand());

        self::assertSame($typeDefault, $resolved);
    }

    public function test_falls_back_to_global_default_when_type_default_missing(): void
    {
        $globalDefault = $this->createMock(MessageMetadataProvider::class);

        $resolver = new MessageMetadataProviderResolver(new ServiceLocator([
            MessageMetadataProviderResolver::DEFAULT_KEY => static fn (): MessageMetadataProvider => $globalDefault,
        ]));

        $resolved = $resolver->resolveFor(new MessageMetadataProviderResolverTestChildCommand());

        self::assertSame($globalDefault, $resolved);
    }

    public function test_requires_a_default_provider(): void
    {
        $resolver = new MessageMetadataProviderResolver(new ServiceLocator([]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Metadata provider resolver must be initialised with a default metadata provider.');

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
