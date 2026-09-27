<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\EventBusInterface;
use SomeWork\CqrsBundle\Exception\OutboxRequiresTransactionException;
use SomeWork\CqrsBundle\Outbox\RecordedEventsPublisher;
use SomeWork\CqrsBundle\Testing\FakeEventBus;
use SomeWork\CqrsBundle\Testing\RecordedDispatch;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Article;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticleFeaturedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticlePublishedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticleRenamedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AssertsMessages;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function array_map;

/**
 * The aggregates are plain objects here: the publisher needs no entity manager.
 */
#[CoversClass(RecordedEventsPublisher::class)]
final class RecordedEventsPublisherTest extends TestCase
{
    use AssertsMessages;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function test_it_stores_the_events_of_each_aggregate_in_order_then_releases_them(): void
    {
        $bus = new FakeEventBus();
        $first = new Article('a1', 'One');
        $first->rename('Two');
        $second = new Article('b1', 'Other');
        $this->connection->beginTransaction();

        (new RecordedEventsPublisher($bus, $this->connection))->publish($first, $second);

        self::assertSameMessages([
            new ArticlePublishedEvent('a1', 'One'),
            new ArticleRenamedEvent('a1', 'Two'),
            new ArticlePublishedEvent('b1', 'Other'),
        ], array_map(static fn (RecordedDispatch $dispatch): object => $dispatch->message, $bus->getDispatched()));
        foreach ($bus->getDispatched() as $dispatch) {
            self::assertSame(DispatchMode::OUTBOX, $dispatch->mode);
        }
        self::assertSame([], $first->recordedEvents());
        self::assertSame([], $second->recordedEvents());
    }

    public function test_an_aggregate_passed_twice_is_published_once(): void
    {
        $bus = new FakeEventBus();
        $article = new Article('a1', 'One');
        $this->connection->beginTransaction();

        (new RecordedEventsPublisher($bus, $this->connection))->publish($article, $article);

        self::assertCount(1, $bus->getDispatched());
    }

    public function test_outside_a_transaction_it_stores_nothing_and_the_events_stay_recorded(): void
    {
        $bus = new FakeEventBus();
        $article = new Article('a1', 'One');

        try {
            (new RecordedEventsPublisher($bus, $this->connection))->publish($article);
            self::fail('The events should have been refused.');
        } catch (OutboxRequiresTransactionException $exception) {
            self::assertSame(ArticlePublishedEvent::class, $exception->messageClass);
            self::assertSame([Article::class], $exception->entityClasses);
            self::assertFalse($exception->afterCommit);
            self::assertStringContainsString('The events recorded by '.Article::class, $exception->getMessage());
        }

        self::assertSame([], $bus->getDispatched());
        self::assertCount(1, $article->recordedEvents());
    }

    public function test_aggregates_without_events_need_no_transaction(): void
    {
        $bus = new FakeEventBus();
        $article = new Article('a1', 'One');
        $article->releaseEvents();

        (new RecordedEventsPublisher($bus, $this->connection))->publish($article);

        self::assertSame([], $bus->getDispatched());
    }

    public function test_a_connection_without_auto_commit_is_always_in_a_transaction(): void
    {
        $bus = new FakeEventBus();
        $this->connection->setAutoCommit(false);

        (new RecordedEventsPublisher($bus, $this->connection))->publish(new Article('a1', 'One'));

        self::assertCount(1, $bus->getDispatched());
    }

    public function test_a_bus_that_does_not_store_the_events_makes_the_transaction_rollback_only_and_the_events_stay_recorded(): void
    {
        $article = new Article('a1', 'One');
        $this->connection->beginTransaction();

        try {
            (new RecordedEventsPublisher($this->bus(static fn (Event $event): Envelope => new Envelope($event)), $this->connection))->publish($article);
            self::fail('The publisher should have noticed that the events were not stored.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('did not store "'.ArticlePublishedEvent::class.'", recorded by '.Article::class.', in the outbox', $exception->getMessage());
        }

        self::assertTrue($this->connection->isRollbackOnly());
        self::assertCount(1, $article->recordedEvents());
    }

    public function test_a_failing_store_makes_the_transaction_rollback_only_and_every_aggregate_keeps_its_events(): void
    {
        $fake = new FakeEventBus();
        $first = new Article('a1', 'One');
        $second = new Article('b1', 'Other');
        $this->connection->beginTransaction();
        $bus = $this->bus(static function (Event $event) use ($fake): Envelope {
            if ([] !== $fake->getDispatched()) {
                throw new \RuntimeException('The outbox table is locked.');
            }

            return $fake->dispatch($event, DispatchMode::OUTBOX);
        });

        try {
            (new RecordedEventsPublisher($bus, $this->connection))->publish($first, $second);
            self::fail('The store should have failed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The outbox table is locked.', $exception->getMessage());
        }

        self::assertTrue($this->connection->isRollbackOnly());
        self::assertCount(1, $first->recordedEvents(), 'Events are released once all are stored.');
        self::assertCount(1, $second->recordedEvents());
    }

    public function test_an_aggregate_recording_while_its_events_are_stored_makes_the_transaction_rollback_only_and_releases_nothing(): void
    {
        $fake = new FakeEventBus();
        $first = new Article('a1', 'One');
        $second = new Article('b1', 'Other');
        $this->connection->beginTransaction();
        $bus = $this->bus(static function (Event $event) use ($fake, $second): Envelope {
            if ($event instanceof ArticlePublishedEvent && 'b1' === $event->articleId) {
                $second->record(new ArticleFeaturedEvent('b1'));
            }

            return $fake->dispatch($event, DispatchMode::OUTBOX);
        });

        try {
            (new RecordedEventsPublisher($bus, $this->connection))->publish($first, $second);
            self::fail('The publisher should have refused an event recorded while storing.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString(Article::class.' has 2 recorded event(s) after 1 were stored', $exception->getMessage());
        }

        self::assertTrue($this->connection->isRollbackOnly());
        self::assertCount(1, $first->recordedEvents(), 'Nothing is released before every aggregate is checked.');
        self::assertCount(2, $second->recordedEvents());
    }

    public function test_a_decorator_of_the_event_bus_that_keeps_the_outbox_mode_is_accepted(): void
    {
        $fake = new FakeEventBus();
        $this->connection->beginTransaction();
        $bus = $this->bus(static fn (Event $event, DispatchMode $mode): Envelope => $fake->dispatch($event, $mode));

        (new RecordedEventsPublisher($bus, $this->connection))->publish(new Article('a1', 'One'));

        self::assertSameMessages([new ArticlePublishedEvent('a1', 'One')], array_map(static fn (RecordedDispatch $dispatch): object => $dispatch->message, $fake->getDispatched()));
    }

    /**
     * @param \Closure(Event, DispatchMode): Envelope $dispatch
     */
    private function bus(\Closure $dispatch): EventBusInterface
    {
        return new class($dispatch) implements EventBusInterface {
            /**
             * @param \Closure(Event, DispatchMode): Envelope $dispatch
             */
            public function __construct(private readonly \Closure $dispatch)
            {
            }

            public function dispatch(Event $event, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope
            {
                return ($this->dispatch)($event, $mode);
            }

            public function dispatchSync(Event $event, StampInterface ...$stamps): Envelope
            {
                throw new \LogicException('Not expected.');
            }

            public function dispatchAsync(Event $event, StampInterface ...$stamps): Envelope
            {
                throw new \LogicException('Not expected.');
            }
        };
    }
}
