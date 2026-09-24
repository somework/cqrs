<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Messenger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Messenger\DeduplicationLockReleaseMiddleware;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
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

    private function bus(\Closure $handler): MessageBus
    {
        $locks = new LockFactory(new InMemoryStore());

        return new MessageBus([
            new DeduplicateMiddleware($locks),
            new DeduplicationLockReleaseMiddleware($locks),
            new HandleMessageMiddleware(new HandlersLocator([CreateTaskCommand::class => [$handler]])),
        ]);
    }
}
