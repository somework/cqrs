<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox\Relay;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Outbox\Relay\RelayServicesResetter;
use SomeWork\CqrsBundle\Tests\Fixture\Service\CallbackResetter;

#[CoversClass(RelayServicesResetter::class)]
final class RelayServicesResetterTest extends TestCase
{
    public function test_resets_doctrine_even_when_services_resetter_would_skip_it(): void
    {
        // services_resetter skips the "doctrine" registry when nothing instantiated it (handlers
        // that autowire an entity manager): a closed entity manager would stay closed.
        $log = new class {
            /** @var list<string> */
            public array $calls = [];
        };
        $doctrine = new CallbackResetter(static function () use ($log): void {
            $log->calls[] = 'doctrine';
        });
        $services = new CallbackResetter(static function () use ($log): void {
            $log->calls[] = 'services';
        });

        (new RelayServicesResetter($services, $doctrine))->reset();
        (new RelayServicesResetter($services, new \stdClass()))->reset();
        (new RelayServicesResetter())->reset();

        self::assertSame(['doctrine', 'services', 'services'], $log->calls);
    }
}
