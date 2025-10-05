<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\MessageMetadataProvider;
use SomeWork\CqrsBundle\Support\MessageMetadataProviderResolver;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class MessageMetadataProviderResolverTest extends TestCase
{
    public function test_resolves_provider_from_class_hierarchy(): void
    {
        $provider = $this->createMock(MessageMetadataProvider::class);

        $resolver = new MessageMetadataProviderResolver(new ServiceLocator([
            MessageMetadataProviderResolver::GLOBAL_DEFAULT_KEY => fn (): MessageMetadataProvider => $this->createMock(MessageMetadataProvider::class),
            MessageMetadataProviderResolver::TYPE_DEFAULT_KEY => fn (): MessageMetadataProvider => $this->createMock(MessageMetadataProvider::class),
            MessageMetadataProviderResolverTestParentCommand::class => static fn (): MessageMetadataProvider => $provider,
        ]));

        $resolved = $resolver->resolveFor(new MessageMetadataProviderResolverTestChildCommand());

        self::assertSame($provider, $resolved);
    }

    public function test_resolves_provider_from_interface_hierarchy(): void
    {
        $provider = $this->createMock(MessageMetadataProvider::class);

        $resolver = new MessageMetadataProviderResolver(new ServiceLocator([
            MessageMetadataProviderResolver::GLOBAL_DEFAULT_KEY => fn (): MessageMetadataProvider => $this->createMock(MessageMetadataProvider::class),
            MessageMetadataProviderResolver::TYPE_DEFAULT_KEY => fn (): MessageMetadataProvider => $this->createMock(MessageMetadataProvider::class),
            MessageMetadataProviderResolverTestInterface::class => static fn (): MessageMetadataProvider => $provider,
        ]));

        $resolved = $resolver->resolveFor(new MessageMetadataProviderResolverTestInterfaceCommand());

        self::assertSame($provider, $resolved);
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
