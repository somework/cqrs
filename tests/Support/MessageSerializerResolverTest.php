<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use LogicException;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class MessageSerializerResolverTest extends TestCase
{
    public function test_resolves_serializer_from_class_hierarchy(): void
    {
        $serializer = $this->createMock(MessageSerializer::class);

        $resolver = new MessageSerializerResolver(new ServiceLocator([
            MessageSerializerResolver::GLOBAL_DEFAULT_KEY => static fn (): MessageSerializer => $this->createMock(MessageSerializer::class),
            MessageSerializerResolver::TYPE_DEFAULT_KEY => static fn (): MessageSerializer => $this->createMock(MessageSerializer::class),
            MessageSerializerResolverTestParentCommand::class => static fn (): MessageSerializer => $serializer,
        ]));

        $resolved = $resolver->resolveFor(new MessageSerializerResolverTestChildCommand());

        self::assertSame($serializer, $resolved);
    }

    public function test_resolves_serializer_from_interface_hierarchy(): void
    {
        $serializer = $this->createMock(MessageSerializer::class);

        $resolver = new MessageSerializerResolver(new ServiceLocator([
            MessageSerializerResolver::GLOBAL_DEFAULT_KEY => static fn (): MessageSerializer => $this->createMock(MessageSerializer::class),
            MessageSerializerResolver::TYPE_DEFAULT_KEY => static fn (): MessageSerializer => $this->createMock(MessageSerializer::class),
            MessageSerializerResolverTestInterface::class => static fn (): MessageSerializer => $serializer,
        ]));

        $resolved = $resolver->resolveFor(new MessageSerializerResolverTestInterfaceCommand());

        self::assertSame($serializer, $resolved);
    }

    public function test_uses_type_default_when_message_not_overridden(): void
    {
        $typeDefault = $this->createMock(MessageSerializer::class);

        $resolver = new MessageSerializerResolver(new ServiceLocator([
            MessageSerializerResolver::GLOBAL_DEFAULT_KEY => static fn (): MessageSerializer => new NullMessageSerializer(),
            MessageSerializerResolver::TYPE_DEFAULT_KEY => static fn (): MessageSerializer => $typeDefault,
        ]));

        $resolved = $resolver->resolveFor(new MessageSerializerResolverTestChildCommand());

        self::assertSame($typeDefault, $resolved);
    }

    public function test_falls_back_to_global_default_when_type_default_missing(): void
    {
        $globalDefault = $this->createMock(MessageSerializer::class);

        $resolver = new MessageSerializerResolver(new ServiceLocator([
            MessageSerializerResolver::GLOBAL_DEFAULT_KEY => static fn (): MessageSerializer => $globalDefault,
        ]));

        $resolved = $resolver->resolveFor(new MessageSerializerResolverTestChildCommand());

        self::assertSame($globalDefault, $resolved);
    }

    public function test_requires_global_default_serializer(): void
    {
        $resolver = new MessageSerializerResolver(new ServiceLocator([]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Serializer resolver must be initialised with a global default serializer.');

        $resolver->resolveFor(new MessageSerializerResolverTestChildCommand());
    }
}

class MessageSerializerResolverTestParentCommand implements Command
{
}

class MessageSerializerResolverTestChildCommand extends MessageSerializerResolverTestParentCommand
{
}

interface MessageSerializerResolverTestInterface extends Command
{
}

class MessageSerializerResolverTestInterfaceCommand implements MessageSerializerResolverTestInterface
{
}
