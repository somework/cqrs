<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use SomeWork\CqrsBundle\Support\TransportResolverMap;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(TransportResolverMap::class)]
final class TransportResolverMapTest extends TestCase
{
    public function test_resolver_for_sync_returns_sync_resolver(): void
    {
        $sync = $this->createResolver();
        $map = new TransportResolverMap(sync: $sync);

        self::assertSame($sync, $map->resolverFor(DispatchMode::SYNC));
    }

    public function test_resolver_for_async_returns_async_resolver(): void
    {
        $async = $this->createResolver();
        $map = new TransportResolverMap(async: $async);

        self::assertSame($async, $map->resolverFor(DispatchMode::ASYNC));
    }

    public function test_resolver_for_default_returns_sync_resolver(): void
    {
        $sync = $this->createResolver();
        $map = new TransportResolverMap(sync: $sync);

        self::assertSame($sync, $map->resolverFor(DispatchMode::DEFAULT));
    }

    public function test_defaults_to_null_when_no_resolvers_provided(): void
    {
        $map = new TransportResolverMap();

        self::assertNull($map->resolverFor(DispatchMode::SYNC));
        self::assertNull($map->resolverFor(DispatchMode::ASYNC));
    }

    public function test_sync_null_async_set(): void
    {
        $async = $this->createResolver();
        $map = new TransportResolverMap(async: $async);

        self::assertNull($map->resolverFor(DispatchMode::SYNC));
        self::assertSame($async, $map->resolverFor(DispatchMode::ASYNC));
    }

    private function createResolver(): MessageTransportResolver
    {
        return new MessageTransportResolver(new ServiceLocator([]));
    }
}
