<?php

declare(strict_types=1);

namespace SomeWork\Cqrs\Tests;

use PHPUnit\Framework\TestCase;
use SomeWork\Cqrs\SomeWorkCqrsBundle;
use SomeWork\Cqrs\DependencyInjection\CqrsExtension;

final class CqrsBundleTest extends TestCase
{
    public function test_bundle_provides_extension_instance(): void
    {
        $bundle = new SomeWorkCqrsBundle();

        self::assertInstanceOf(CqrsExtension::class, $bundle->getContainerExtension());
    }

    public function test_extension_alias_uses_vendor_prefix(): void
    {
        $extension = new CqrsExtension();

        self::assertSame('somework_cqrs', $extension->getAlias());
    }
}
