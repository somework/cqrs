<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Support\MessageTypeLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class MessageTypeLocatorTest extends TestCase
{
    public function test_matches_exact_class_before_parent(): void
    {
        $expected = new \stdClass();

        $locator = new ServiceLocator([
            MessageTypeLocatorChild::class => static fn (): object => $expected,
            MessageTypeLocatorParent::class => static fn (): object => new \stdClass(),
        ]);

        $match = MessageTypeLocator::match($locator, new MessageTypeLocatorChild());

        self::assertNotNull($match);
        self::assertSame(MessageTypeLocatorChild::class, $match->type);
        self::assertSame($expected, $match->service);
    }

    public function test_matches_parent_class_when_child_not_configured(): void
    {
        $expected = new \stdClass();

        $locator = new ServiceLocator([
            MessageTypeLocatorParent::class => static fn (): object => $expected,
        ]);

        $match = MessageTypeLocator::match($locator, new MessageTypeLocatorChild());

        self::assertNotNull($match);
        self::assertSame(MessageTypeLocatorParent::class, $match->type);
        self::assertSame($expected, $match->service);
    }

    public function test_prefers_more_specific_interface(): void
    {
        $childService = new \stdClass();
        $parentService = new \stdClass();

        $locator = new ServiceLocator([
            MessageTypeLocatorExtendedInterface::class => static fn (): object => $childService,
            MessageTypeLocatorInterface::class => static fn (): object => $parentService,
        ]);

        $match = MessageTypeLocator::match($locator, new MessageTypeLocatorImplementsInterface());

        self::assertNotNull($match);
        self::assertSame(MessageTypeLocatorExtendedInterface::class, $match->type);
        self::assertSame($childService, $match->service);
    }

    public function test_matches_interface_hierarchy_when_specific_not_configured(): void
    {
        $expected = new \stdClass();

        $locator = new ServiceLocator([
            MessageTypeLocatorInterface::class => static fn (): object => $expected,
        ]);

        $match = MessageTypeLocator::match($locator, new MessageTypeLocatorImplementsInterface());

        self::assertNotNull($match);
        self::assertSame(MessageTypeLocatorInterface::class, $match->type);
        self::assertSame($expected, $match->service);
    }

    public function test_ignores_excluded_keys(): void
    {
        $expected = new \stdClass();

        $locator = new ServiceLocator([
            MessageTypeLocatorChild::class => static fn (): object => new \stdClass(),
            MessageTypeLocatorParent::class => static fn (): object => $expected,
        ]);

        $match = MessageTypeLocator::match($locator, new MessageTypeLocatorChild(), [MessageTypeLocatorChild::class]);

        self::assertNotNull($match);
        self::assertSame(MessageTypeLocatorParent::class, $match->type);
        self::assertSame($expected, $match->service);
    }
}

class MessageTypeLocatorParent
{
}

class MessageTypeLocatorChild extends MessageTypeLocatorParent
{
}

interface MessageTypeLocatorInterface
{
}

interface MessageTypeLocatorExtendedInterface extends MessageTypeLocatorInterface
{
}

class MessageTypeLocatorImplementsInterface implements MessageTypeLocatorExtendedInterface
{
}
