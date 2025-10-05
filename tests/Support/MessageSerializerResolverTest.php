<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class MessageSerializerResolverTest extends TestCase
{
    public function test_resolves_serializer_from_class_hierarchy(): void
    {
        $serializer = $this->createMock(MessageSerializer::class);

        $resolver = new MessageSerializerResolver(new ServiceLocator([
            MessageSerializerResolver::GLOBAL_DEFAULT_KEY => fn (): MessageSerializer => $this->createMock(MessageSerializer::class),
            MessageSerializerResolver::TYPE_DEFAULT_KEY => fn (): MessageSerializer => $this->createMock(MessageSerializer::class),
            MessageSerializerResolverTestParentCommand::class => static fn (): MessageSerializer => $serializer,
        ]));

        $resolved = $resolver->resolveFor(new MessageSerializerResolverTestChildCommand());

        self::assertSame($serializer, $resolved);
    }

    public function test_resolves_serializer_from_interface_hierarchy(): void
    {
        $serializer = $this->createMock(MessageSerializer::class);

        $resolver = new MessageSerializerResolver(new ServiceLocator([
            MessageSerializerResolver::GLOBAL_DEFAULT_KEY => fn (): MessageSerializer => $this->createMock(MessageSerializer::class),
            MessageSerializerResolver::TYPE_DEFAULT_KEY => fn (): MessageSerializer => $this->createMock(MessageSerializer::class),
            MessageSerializerResolverTestInterface::class => static fn (): MessageSerializer => $serializer,
        ]));

        $resolved = $resolver->resolveFor(new MessageSerializerResolverTestInterfaceCommand());

        self::assertSame($serializer, $resolved);
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
