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

    public function test_match_caches_service_identifier_for_same_signature(): void
    {
        $expected = new \stdClass();

        $locator = new SpyServiceLocator([
            MessageTypeLocatorChild::class => static fn (): object => $expected,
            MessageTypeLocatorParent::class => static fn (): object => new \stdClass(),
        ]);

        $message = new MessageTypeLocatorChild();

        $firstMatch = MessageTypeLocator::match($locator, $message);
        $secondMatch = MessageTypeLocator::match($locator, $message);

        self::assertNotNull($firstMatch);
        self::assertNotNull($secondMatch);
        self::assertSame(MessageTypeLocatorChild::class, $firstMatch->type);
        self::assertSame(MessageTypeLocatorChild::class, $secondMatch->type);
        self::assertSame([$expected, $expected], [$firstMatch->service, $secondMatch->service]);

        self::assertSame([MessageTypeLocatorChild::class], $locator->hasCalls);
        self::assertSame([MessageTypeLocatorChild::class, MessageTypeLocatorChild::class], $locator->getCalls);
    }

    public function test_match_recomputes_when_ignored_keys_change(): void
    {
        $child = new \stdClass();
        $parent = new \stdClass();

        $locator = new SpyServiceLocator([
            MessageTypeLocatorChild::class => static fn (): object => $child,
            MessageTypeLocatorParent::class => static fn (): object => $parent,
        ]);

        $message = new MessageTypeLocatorChild();

        $firstMatch = MessageTypeLocator::match($locator, $message);
        $initialHasCalls = $locator->hasCalls;

        $secondMatch = MessageTypeLocator::match($locator, $message, [MessageTypeLocatorChild::class]);

        self::assertNotNull($firstMatch);
        self::assertNotNull($secondMatch);
        self::assertSame(MessageTypeLocatorChild::class, $firstMatch->type);
        self::assertSame(MessageTypeLocatorParent::class, $secondMatch->type);
        self::assertSame($child, $firstMatch->service);
        self::assertSame($parent, $secondMatch->service);

        self::assertSame([MessageTypeLocatorChild::class], $initialHasCalls);
        self::assertSame([MessageTypeLocatorChild::class, MessageTypeLocatorParent::class], $locator->hasCalls);
        self::assertSame([MessageTypeLocatorChild::class, MessageTypeLocatorParent::class], $locator->getCalls);
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

final class SpyServiceLocator extends ServiceLocator
{
    /** @var list<string> */
    public array $hasCalls = [];

    /** @var list<string> */
    public array $getCalls = [];

    public function has(string $id): bool
    {
        $this->hasCalls[] = $id;

        return parent::has($id);
    }

    public function get(string $id): mixed
    {
        $this->getCalls[] = $id;

        return parent::get($id);
    }
}
