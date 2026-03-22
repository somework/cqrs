<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Support\MessageTypeLocator;
use SomeWork\CqrsBundle\Support\MessageTypeLocatorResetter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\Service\ResetInterface;

#[CoversClass(MessageTypeLocatorResetter::class)]
final class MessageTypeLocatorResetterTest extends TestCase
{
    public function test_implements_reset_interface(): void
    {
        $interfaces = class_implements(MessageTypeLocatorResetter::class);

        self::assertIsArray($interfaces);
        self::assertArrayHasKey(ResetInterface::class, $interfaces);
    }

    public function test_reset_clears_message_type_locator_cache(): void
    {
        $service = new \stdClass();
        $locator = new ServiceLocator([
            \stdClass::class => static fn () => $service,
        ]);

        // Populate cache
        $match = MessageTypeLocator::match($locator, new \stdClass());
        self::assertNotNull($match);

        // Reset via resetter
        $resetter = new MessageTypeLocatorResetter();
        $resetter->reset();

        // After reset, a different locator with same class should not hit old cache
        $newService = new \stdClass();
        $newLocator = new ServiceLocator([
            \stdClass::class => static fn () => $newService,
        ]);

        $newMatch = MessageTypeLocator::match($newLocator, new \stdClass());
        self::assertNotNull($newMatch);
        self::assertSame($newService, $newMatch->service);
    }
}
