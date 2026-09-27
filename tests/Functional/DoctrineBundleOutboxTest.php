<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Stamp\OutboxStoredStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\DoctrineBundleTestKernel;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskArchivedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\TestDatabase;
use SomeWork\CqrsBundle\Tests\Fixture\Service\FlushCounter;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bridge\Doctrine\Messenger\DoctrineOpenTransactionLoggerMiddleware;
use Symfony\Bridge\Doctrine\Messenger\DoctrineTransactionMiddleware;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

use function class_exists;
use function in_array;
use function sprintf;

/**
 * The outbox with the real DoctrineBundle: the fake middleware of the other kernels would not
 * notice DoctrineBundle renaming or adding the transaction middleware the outbox store must skip.
 */
#[Group('doctrine-bundle')]
#[Group('database')]
#[CoversNothing]
final class DoctrineBundleOutboxTest extends KernelTestCase
{
    /**
     * The middleware that belongs to handling (it flushes, opens a transaction around the store or
     * reports the caller's open transaction), by class; the DBAL ones exist from DoctrineBridge 8.2.
     */
    private const HANDLING_MIDDLEWARE = [
        DoctrineTransactionMiddleware::class,
        DoctrineOpenTransactionLoggerMiddleware::class,
        'Symfony\Bridge\Doctrine\Messenger\DoctrineDbalTransactionMiddleware',
        'Symfony\Bridge\Doctrine\Messenger\DoctrineDbalOpenTransactionLoggerMiddleware',
    ];

    private const BYPASS_PREFIX = 'somework_cqrs.messenger.middleware.outbox_store.bypass.';

    protected static function getKernelClass(): string
    {
        return DoctrineBundleTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // On a real database, drops the tables the other tests left.
        TestDatabase::connect()->close();
        self::bootKernel();
        self::assertSame(Command::SUCCESS, $this->console('somework:cqrs:outbox:setup')->getStatusCode());
    }

    public function test_the_outbox_skips_every_transaction_middleware_of_doctrine_bundle(): void
    {
        $inventory = self::getContainer()->getParameter('cqrs_test.doctrine_middleware');
        self::assertIsArray($inventory);
        self::assertArrayHasKey('messenger.middleware.doctrine_transaction', $inventory);
        self::assertArrayHasKey('messenger.middleware.doctrine_open_transaction_logger', $inventory);

        foreach ($inventory as $id => $middleware) {
            self::assertIsArray($middleware);
            self::assertTrue($middleware['listed'], sprintf('"%s" is on the bus.', $id));
            self::assertSame(
                in_array($middleware['class'], self::HANDLING_MIDDLEWARE, true),
                $middleware['bypassed'],
                sprintf('"%s" (%s) is skipped by a message stored in the outbox if, and only if, it belongs to handling.', $id, $middleware['class']),
            );
        }
    }

    public function test_doctrine_transaction_runs_in_the_worker_and_not_when_the_message_is_stored(): void
    {
        $flushes = self::getContainer()->get(FlushCounter::class);
        self::assertInstanceOf(FlushCounter::class, $flushes);

        $envelope = $this->connection()->transactional(fn () => $this->eventBus()->dispatch(new TaskArchivedEvent('task-1')));

        self::assertInstanceOf(OutboxStoredStamp::class, $envelope->last(OutboxStoredStamp::class));
        self::assertSame(0, $flushes->flushes, 'doctrine_transaction did not flush the entity manager in the caller\'s transaction.');

        self::assertSame(Command::SUCCESS, $this->console('somework:cqrs:outbox:relay')->getStatusCode());
        self::assertCount(1, $this->transport()->getSent());
        self::assertSame(Command::SUCCESS, $this->console('messenger:consume', ['receivers' => ['async'], '--limit' => '1', '--time-limit' => '5'])->getStatusCode());

        $recorder = self::getContainer()->get(TaskRecorder::class);
        self::assertInstanceOf(TaskRecorder::class, $recorder);
        self::assertSame(['task-1'], $recorder->events());
        self::assertGreaterThan(0, $flushes->flushes, 'doctrine_transaction flushed the entity manager around the handler.');
    }

    public function test_the_middleware_of_the_bus_the_message_is_stored_through_is_wrapped(): void
    {
        $middleware = self::getContainer()->getParameter('cqrs_test.event_async_bus_middleware');
        self::assertIsArray($middleware);

        $expected = ['event.async_bus.middleware.doctrine_transaction', 'event.async_bus.middleware.doctrine_open_transaction_logger'];
        // Registered under its class name, as Symfony's documentation does (DoctrineBridge 8.2+).
        if (class_exists(DoctrineBundleTestKernel::DBAL_TRANSACTION_MIDDLEWARE)) {
            $expected[] = DoctrineBundleTestKernel::DBAL_TRANSACTION_MIDDLEWARE;
        }
        foreach ($expected as $id) {
            self::assertContains(self::BYPASS_PREFIX.$id, $middleware);
            self::assertNotContains($id, $middleware);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(string $command, array $input = []): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find($command));
        $tester->execute($input);

        return $tester;
    }

    private function eventBus(): EventBus
    {
        $bus = self::getContainer()->get(EventBus::class);
        self::assertInstanceOf(EventBus::class, $bus);

        return $bus;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
