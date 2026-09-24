<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Messenger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Messenger\DeduplicationLockReleaseMiddleware;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Service\RecordingLogger;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\DeduplicateMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(DeduplicationLockReleaseMiddleware::class)]
#[RequiresMethod(DeduplicateStamp::class, '__construct')]
final class DeduplicationLockReleaseMiddlewareTest extends TestCase
{
    public function test_failed_synchronous_handling_releases_the_key_so_a_retry_runs(): void
    {
        $attempts = 0;
        $bus = $this->bus(static function () use (&$attempts): string {
            if (1 === ++$attempts) {
                throw new \RuntimeException('Temporary failure');
            }

            return 'done';
        });

        try {
            $bus->dispatch(new CreateTaskCommand('1', 'x'), [new DeduplicateStamp('task-1')]);
            self::fail('Expected the first attempt to fail.');
        } catch (\RuntimeException) {
        }

        $envelope = $bus->dispatch(new CreateTaskCommand('1', 'x'), [new DeduplicateStamp('task-1')]);

        self::assertSame(2, $attempts);
        self::assertSame('done', $envelope->last(HandledStamp::class)?->getResult());
    }

    public function test_successful_handling_keeps_deduplicating(): void
    {
        $attempts = 0;
        $bus = $this->bus(static function () use (&$attempts): string {
            ++$attempts;

            return 'done';
        });

        $bus->dispatch(new CreateTaskCommand('1', 'x'), [new DeduplicateStamp('task-1')]);
        $duplicate = $bus->dispatch(new CreateTaskCommand('1', 'x'), [new DeduplicateStamp('task-1')]);

        self::assertSame(1, $attempts);
        self::assertNull($duplicate->last(HandledStamp::class));
    }

    public function test_a_failing_release_does_not_hide_the_handler_exception(): void
    {
        $store = new class implements PersistingStoreInterface {
            public function save(Key $key): void
            {
            }

            public function delete(Key $key): void
            {
                throw new \RuntimeException('Lock store unavailable');
            }

            public function exists(Key $key): bool
            {
                return true;
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
            }
        };
        $logger = new RecordingLogger();
        $bus = $this->bus(static fn (): never => throw new \DomainException('Insufficient funds'), new LockFactory($store), $logger);

        try {
            $bus->dispatch(new CreateTaskCommand('1', 'x'), [new DeduplicateStamp('task-1')]);
            self::fail('Expected the handler exception.');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(\DomainException::class, $exception->getPrevious());
        }

        self::assertTrue($logger->hasRecordContaining('warning', 'Could not release the deduplication lock'));
    }

    private function bus(\Closure $handler, ?LockFactory $locks = null, ?RecordingLogger $logger = null): MessageBus
    {
        $locks ??= new LockFactory(new InMemoryStore());

        return new MessageBus([
            new DeduplicateMiddleware($locks),
            new DeduplicationLockReleaseMiddleware($locks, $logger),
            new HandleMessageMiddleware(new HandlersLocator([CreateTaskCommand::class => [$handler]])),
        ]);
    }
}
