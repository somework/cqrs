<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Messenger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Messenger\DeduplicationLockReleaseMiddleware;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Service\RecordingLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\Exception\UnserializableKeyException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\DeduplicateMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function bin2hex;
use function random_bytes;
use function sprintf;

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

    public function test_a_failed_attempt_of_a_worker_keeps_the_key(): void
    {
        // The key was taken when the message was sent and travels with it; Messenger retries the received message itself.
        $locks = new LockFactory(new InMemoryStore());
        $stamp = new DeduplicateStamp('task-1');
        $locks->createLockFromKey($stamp->getKey(), 300, false)->acquire();
        $attempts = 0;
        $bus = $this->bus(static function () use (&$attempts): string {
            if (1 === ++$attempts) {
                throw new \RuntimeException('Temporary failure');
            }

            return 'done';
        }, $locks);

        try {
            $bus->dispatch(new Envelope(new CreateTaskCommand('1', 'x'), [$stamp, new ReceivedStamp('async')]));
            self::fail('Expected the worker attempt to fail.');
        } catch (HandlerFailedException) {
        }

        $duplicate = $bus->dispatch(new CreateTaskCommand('1', 'x'), [new DeduplicateStamp('task-1')]);

        self::assertSame(1, $attempts, 'A new dispatch is still deduplicated while the worker retries.');
        self::assertNull($duplicate->last(HandledStamp::class));
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

    public function test_an_async_dispatch_with_a_store_that_cannot_serialize_keys_is_explained(): void
    {
        $locks = new LockFactory(new FlockStore());
        $transport = new InMemoryTransport(new PhpSerializer());
        $bus = new MessageBus([
            new DeduplicateMiddleware($locks),
            new DeduplicationLockReleaseMiddleware($locks),
            new SendMessageMiddleware(new SendersLocator([CreateTaskCommand::class => ['async']], new ServiceLocator(['async' => static fn (): InMemoryTransport => $transport]))),
        ]);
        $stamp = new DeduplicateStamp('flock-'.bin2hex(random_bytes(4)));

        try {
            $bus->dispatch(new CreateTaskCommand('1', 'x'), [$stamp]);
            self::fail('Expected the unserializable key to be reported.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString(sprintf('The idempotency lock of "%s" cannot be sent to a transport', CreateTaskCommand::class), $exception->getMessage());
            self::assertInstanceOf(UnserializableKeyException::class, $exception->getPrevious());
        }

        self::assertSame([], $transport->getSent());
        self::assertTrue($locks->createLockFromKey($stamp->getKey())->acquire(), 'The lock was released.');
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
