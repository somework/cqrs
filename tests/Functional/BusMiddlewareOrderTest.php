<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\MiddlewareOrderTestKernel;
use SomeWork\CqrsBundle\Tests\Fixture\Service\CallerContextMiddleware;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Middleware\DecodeFailedMessageMiddleware;
use Symfony\Component\Stopwatch\Stopwatch;

use function array_keys;
use function class_exists;

/**
 * Snapshot of the middleware of every CQRS bus with every middleware of the bundle enabled. The
 * bundle's middleware must be there whatever priority the Symfony version gives MessengerPass
 * (0 up to 8.1, -16 from 8.2 on), in the order the bundle documents. Only the middleware that
 * Messenger itself has in some versions only depends on the installed version.
 */
#[CoversNothing]
final class BusMiddlewareOrderTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MiddlewareOrderTestKernel::class;
    }

    /**
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function buses(): iterable
    {
        yield 'command' => ['command.bus', ['somework_cqrs.messenger.middleware.outbox_store.bypass.command.bus.middleware.doctrine_transaction', CallerContextMiddleware::class], false];
        yield 'command_async' => ['command.async_bus', [], false];
        yield 'query' => ['query.bus', [], false];
        yield 'event' => ['event.bus', [], true];
        yield 'event_async' => ['event.async_bus', ['somework_cqrs.messenger.middleware.outbox_store.bypass.event.async_bus.middleware.doctrine_dbal_transaction'], true];
    }

    /**
     * @param list<string> $applicationMiddleware the middleware of the bus configuration, as the outbox wraps it
     */
    #[DataProvider('buses')]
    public function test_the_middleware_of_the_bus(string $busId, array $applicationMiddleware, bool $isEventBus): void
    {
        self::assertSame(self::expectedMiddleware($busId, $applicationMiddleware, $isEventBus), self::snapshot()[$busId] ?? null);
    }

    public function test_every_cqrs_bus_is_in_the_snapshot(): void
    {
        self::assertSame(MiddlewareOrderTestKernel::BUSES, array_keys(self::snapshot()));
    }

    /**
     * @param list<string> $applicationMiddleware
     *
     * @return list<string>
     */
    private static function expectedMiddleware(string $busId, array $applicationMiddleware, bool $isEventBus): array
    {
        return [
            // Before dispatch_after_current_bus: a deferred message keeps the trace it was dispatched in.
            'somework_cqrs.messenger.middleware.trace_context_capture',
            // Debug mode.
            ...(class_exists(Stopwatch::class) ? [$busId.'.middleware.traceable'] : []),
            'messenger.middleware.add_default_stamps_middleware',
            'somework_cqrs.messenger.middleware.outbox_prepare',
            $busId.'.middleware.add_bus_name_stamp_middleware',
            'messenger.middleware.reject_redelivered_message_middleware',
            'messenger.middleware.dispatch_after_current_bus',
            // Messenger 8.1.
            ...(class_exists(DecodeFailedMessageMiddleware::class) ? ['messenger.middleware.decode_failed_message_middleware'] : []),
            'somework_cqrs.messenger.middleware.open_telemetry',
            'somework_cqrs.messenger.middleware.causation_id',
            ...($isEventBus ? ['somework_cqrs.messenger.middleware.allow_no_handler'] : []),
            'messenger.middleware.failed_message_processing_middleware',
            'messenger.middleware.deduplicate_middleware',
            'somework_cqrs.messenger.middleware.deduplication_lock_release',
            ...$applicationMiddleware,
            'somework_cqrs.messenger.middleware.outbox_store',
            $busId.'.middleware.send_message',
            $busId.'.middleware.handle_message',
        ];
    }

    /**
     * @return array<mixed> the middleware ids of each bus, keyed by bus id
     */
    private static function snapshot(): array
    {
        self::bootKernel();
        $snapshot = self::getContainer()->getParameter('cqrs_test.bus_middleware');
        self::assertIsArray($snapshot);

        return $snapshot;
    }
}
