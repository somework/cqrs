<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\CommitFailedRollbackOnly;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ConnectionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\EventBusInterface;
use SomeWork\CqrsBundle\Doctrine\PendingFlush;
use SomeWork\CqrsBundle\Doctrine\RecordedEventsListener;
use SomeWork\CqrsBundle\Exception\OutboxRequiresTransactionException;
use SomeWork\CqrsBundle\Messenger\OutboxPrepareMiddleware;
use SomeWork\CqrsBundle\Messenger\OutboxStoreMiddleware;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\Outbox\RecordedEventsPublisher;
use SomeWork\CqrsBundle\Tests\Fixture\Doctrine\FailingOnFlushListener;
use SomeWork\CqrsBundle\Tests\Fixture\Doctrine\TestEntityManager;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Account;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Article;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Comment;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Document;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Report;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Tag;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AccountCreditedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticleDeletedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticleFeaturedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticlePublishedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticleRenamedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AssertsMessages;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CommentPostedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ReportFiledEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\TestDatabase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function array_map;
use function class_exists;
use function is_string;

/**
 * The listener on a real entity manager and outbox table: in-memory SQLite, or the database of
 * CQRS_TEST_DATABASE_URL (PostgreSQL and MySQL in CI).
 */
#[CoversClass(RecordedEventsListener::class)]
#[CoversClass(PendingFlush::class)]
#[Group('database')]
final class RecordedEventsListenerTest extends TestCase
{
    use AssertsMessages;

    private Connection $connection;
    private DbalOutboxStorage $storage;
    private EventManager $events;
    private EntityManager $entityManager;
    private RecordedEventsListener $listener;
    private bool $requireTransaction = true;
    private ?EventBusInterface $eventBus = null;
    private ?Connection $outboxConnection = null;

    /** @var list<MiddlewareInterface> Middleware of the event bus, before the store */
    private array $middleware = [];

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connect();
        $this->storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $this->storage->setup();
        $this->events = new EventManager();
        $this->entityManager = TestEntityManager::create($this->connection, $this->events);
        TestEntityManager::createSchema($this->entityManager);
    }

    public function test_the_events_recorded_before_a_flush_are_stored_in_its_transaction_then_released(): void
    {
        $this->listen();
        $article = new Article('a1', 'Draft');

        $this->entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager) use ($article): void {
            $entityManager->persist($article);
            $entityManager->flush();
            $article->rename('Final');
        });

        self::assertSame([], $article->recordedEvents());
        self::assertSameMessages([new ArticlePublishedEvent('a1', 'Draft'), new ArticleRenamedEvent('a1', 'Final')], $this->storedEvents());
    }

    public function test_the_rows_roll_back_with_the_changes(): void
    {
        $this->listen();
        $this->connection->beginTransaction();
        $this->entityManager->persist(new Article('a1', 'Draft'));
        $this->entityManager->flush();
        self::assertSame(1, $this->rowCount('somework_cqrs_outbox'));

        $this->connection->rollBack();

        self::assertSame(0, $this->rowCount('somework_cqrs_outbox'));
        self::assertSame(0, $this->rowCount('cqrs_test_article'));
    }

    public function test_a_flush_outside_a_transaction_is_refused_before_it_writes_and_the_entities_keep_their_events(): void
    {
        // The writer's option does not apply to recorded events.
        $this->requireTransaction = false;
        $this->listen();
        $article = new Article('a1', 'Draft');
        $this->entityManager->persist($article);

        $exception = self::thrown(function (): void {
            $this->entityManager->flush();
        });

        self::assertInstanceOf(OutboxRequiresTransactionException::class, $exception);
        self::assertSame(ArticlePublishedEvent::class, $exception->messageClass);
        self::assertSame([Article::class], $exception->entityClasses);
        self::assertFalse($exception->afterCommit);

        self::assertSame(0, $this->rowCount('cqrs_test_article'));
        self::assertTrue($this->entityManager->isOpen());
        self::assertSameMessages([new ArticlePublishedEvent('a1', 'Draft')], $article->recordedEvents());

        $this->entityManager->wrapInTransaction(static function (): void {
        });

        self::assertSame(1, $this->rowCount('cqrs_test_article'));
        self::assertSameMessages([new ArticlePublishedEvent('a1', 'Draft')], $this->storedEvents());
    }

    public function test_the_events_recorded_during_a_flush_without_a_transaction_are_refused_after_its_commit_and_kept(): void
    {
        $this->listen();
        $comment = new Comment('a1');
        $this->entityManager->persist($comment);

        $exception = self::thrown(function (): void {
            $this->entityManager->flush();
        });

        self::assertInstanceOf(OutboxRequiresTransactionException::class, $exception);
        self::assertSame(CommentPostedEvent::class, $exception->messageClass);
        self::assertSame([Comment::class], $exception->entityClasses);
        self::assertTrue($exception->afterCommit);

        self::assertSame(1, $this->rowCount('cqrs_test_comment'), 'The flush committed the comment.');
        self::assertSame(0, $this->rowCount('somework_cqrs_outbox'));
        self::assertCount(1, $comment->recordedEvents());

        $this->entityManager->wrapInTransaction(static function (): void {
        });

        self::assertSameMessages([new CommentPostedEvent((int) $comment->id(), 'a1')], $this->storedEvents());
    }

    public function test_the_id_the_database_generates_is_known_to_the_events_recorded_after_the_insert(): void
    {
        $this->listen();
        $comment = new Comment('a1');

        $this->entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager) use ($comment): void {
            $entityManager->persist($comment);
        });

        self::assertGreaterThan(0, $comment->id());
        self::assertSameMessages([new CommentPostedEvent((int) $comment->id(), 'a1')], $this->storedEvents());
    }

    public function test_a_failed_flush_that_the_caller_swallows_leaves_no_rows(): void
    {
        $this->listen();
        $this->connection->insert('cqrs_test_article', ['id' => 'taken', 'title' => 'Existing']);
        $this->connection->beginTransaction();
        $this->entityManager->persist(new Article('a1', 'Draft'));
        $this->entityManager->persist(new Article('taken', 'Duplicate'));

        try {
            $this->entityManager->flush();
            self::fail('The flush should have failed.');
        } catch (UniqueConstraintViolationException) {
        }
        // The flush ran in a savepoint, which it rolled back: the caller can still commit.
        $this->connection->commit();

        self::assertFalse($this->entityManager->isOpen());
        self::assertSame(0, $this->rowCount('somework_cqrs_outbox'));
        self::assertSame(1, $this->rowCount('cqrs_test_article'));
    }

    public function test_an_entity_whose_mapped_fields_did_not_change_has_its_events_stored(): void
    {
        $this->connection->insert('cqrs_test_article', ['id' => 'a1', 'title' => 'Existing']);
        $this->listen();
        $article = $this->entityManager->find(Article::class, 'a1');
        self::assertInstanceOf(Article::class, $article);
        self::assertSame([], $article->recordedEvents(), 'A loaded entity starts without events.');
        $article->feature();

        $this->entityManager->wrapInTransaction(static function (): void {
        });

        self::assertSameMessages([new ArticleFeaturedEvent('a1')], $this->storedEvents());
    }

    public function test_a_removed_entity_has_its_events_stored(): void
    {
        $this->connection->insert('cqrs_test_article', ['id' => 'a1', 'title' => 'Existing']);
        $this->listen();
        $article = $this->entityManager->find(Article::class, 'a1');
        self::assertInstanceOf(Article::class, $article);

        $this->entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager) use ($article): void {
            $article->delete();
            $entityManager->remove($article);
        });

        self::assertSame(0, $this->rowCount('cqrs_test_article'));
        self::assertSameMessages([new ArticleDeletedEvent('a1')], $this->storedEvents());
    }

    public function test_the_events_of_one_entity_keep_their_order(): void
    {
        $this->listen();
        $first = new Article('a1', 'One');
        $first->rename('Two');

        $this->entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager) use ($first): void {
            $entityManager->persist($first);
            $entityManager->persist(new Article('b1', 'Other'));
        });

        self::assertSameMessages([
            new ArticlePublishedEvent('a1', 'One'),
            new ArticleRenamedEvent('a1', 'Two'),
            new ArticlePublishedEvent('b1', 'Other'),
        ], $this->storedEvents());
    }

    public function test_a_subclass_that_records_events_under_a_root_class_that_does_not(): void
    {
        $this->listen();

        $this->entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager): void {
            $entityManager->persist(new Document('d1'));
            $entityManager->persist(new Report('r1'));
        });

        self::assertSameMessages([new ReportFiledEvent('r1')], $this->storedEvents());
    }

    public function test_entities_of_classes_that_record_nothing_are_flushed_as_usual(): void
    {
        $this->listen();

        $this->entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager): void {
            $entityManager->persist(new Tag('t1', 'News'));
            $entityManager->persist(new Article('a1', 'Draft'));
        });

        self::assertSame(1, $this->rowCount('cqrs_test_tag'));
        self::assertSameMessages([new ArticlePublishedEvent('a1', 'Draft')], $this->storedEvents());
    }

    public function test_an_uninitialized_proxy_is_not_loaded(): void
    {
        $this->connection->insert('cqrs_test_article', ['id' => 'gone', 'title' => 'Deleted elsewhere']);
        $this->listen();
        $proxy = $this->entityManager->getReference(Article::class, 'gone');
        // Loading it now would fail (EntityNotFoundException).
        $this->connection->delete('cqrs_test_article', ['id' => 'gone']);

        $this->entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager): void {
            $entityManager->persist(new Article('a1', 'Draft'));
        });

        self::assertTrue($this->entityManager->getUnitOfWork()->isUninitializedObject($proxy));
        self::assertSameMessages([new ArticlePublishedEvent('a1', 'Draft')], $this->storedEvents());
    }

    public function test_the_entities_a_later_onflush_listener_persists_have_their_events_stored(): void
    {
        $this->listen();
        // Registered after the bundle's listener: it runs after the collection of onFlush.
        $this->events->addEventListener([Events::onFlush], new class {
            private bool $done = false;

            public function onFlush(OnFlushEventArgs $args): void
            {
                if ($this->done) {
                    return;
                }
                $this->done = true;
                $entityManager = $args->getObjectManager();
                $late = new Article('late', 'Late');
                $entityManager->persist($late);
                $entityManager->getUnitOfWork()->computeChangeSet($entityManager->getClassMetadata(Article::class), $late);
            }
        });

        $this->entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager): void {
            $entityManager->persist(new Article('a1', 'Draft'));
        });

        self::assertSameMessages([new ArticlePublishedEvent('a1', 'Draft'), new ArticlePublishedEvent('late', 'Late')], $this->storedEvents());
    }

    public function test_a_flush_of_an_earlier_postflush_listener_is_merged(): void
    {
        $article = new Article('a1', 'Draft');
        $this->onPostFlush(static function (EntityManagerInterface $entityManager) use ($article): void {
            $article->rename('Renamed');
            $entityManager->flush();
        });
        $this->listen();

        $this->entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager) use ($article): void {
            $entityManager->persist($article);
        });

        self::assertSameMessages([new ArticlePublishedEvent('a1', 'Draft'), new ArticleRenamedEvent('a1', 'Renamed')], $this->storedEvents());
        self::assertSame('Renamed', $this->connection->fetchOne('SELECT title FROM cqrs_test_article'));
    }

    public function test_a_failed_nested_flush_that_a_listener_swallows_makes_the_transaction_rollback_only(): void
    {
        $this->connection->insert('cqrs_test_article', ['id' => 'taken', 'title' => 'Existing']);
        $this->onPostFlush(static function (EntityManagerInterface $entityManager): void {
            $entityManager->persist(new Article('taken', 'Duplicate'));
            try {
                $entityManager->flush();
            } catch (UniqueConstraintViolationException) {
            }
        });
        $this->listen();
        $this->connection->beginTransaction();
        $article = new Article('a1', 'Draft');
        $this->entityManager->persist($article);

        $this->assertFailsAfterWrite('cleared, closed or reset during a flush', function (): void {
            $this->entityManager->flush();
        }, 1);
    }

    public function test_a_clear_in_an_earlier_postflush_listener_makes_the_transaction_rollback_only(): void
    {
        $this->onPostFlush(static function (EntityManagerInterface $entityManager): void {
            $entityManager->clear();
        });
        $this->listen();
        $this->connection->beginTransaction();
        $this->entityManager->persist(new Article('a1', 'Draft'));

        $this->assertFailsAfterWrite('cleared, closed or reset during a flush', function (): void {
            $this->entityManager->flush();
        });
    }

    public function test_a_flush_after_a_clear_in_an_earlier_postflush_listener_makes_the_transaction_rollback_only(): void
    {
        $this->onPostFlush(static function (EntityManagerInterface $entityManager): void {
            $entityManager->clear();
            $entityManager->persist(new Article('b1', 'Other'));
            $entityManager->flush();
        });
        $this->listen();
        $this->connection->beginTransaction();
        $this->entityManager->persist(new Article('a1', 'Draft'));

        $this->assertFailsAfterWrite('cleared or reset during a flush (in a flush listener) and flushed again', function (): void {
            $this->entityManager->flush();
        });
    }

    public function test_a_reset_of_the_entity_manager_in_an_earlier_postflush_listener_makes_the_transaction_rollback_only(): void
    {
        $this->onPostFlush(static function (EntityManagerInterface $entityManager): void {
            self::resetInPlace($entityManager);
        });
        $this->listen();
        $this->connection->beginTransaction();
        $this->entityManager->persist(new Article('a1', 'Draft'));

        $this->assertFailsAfterWrite('cleared, closed or reset during a flush', function (): void {
            $this->entityManager->flush();
        });
    }

    public function test_an_entity_manager_reset_after_a_failed_flush_starts_afresh(): void
    {
        $this->connection->insert('cqrs_test_article', ['id' => 'taken', 'title' => 'Existing']);
        $this->listen();
        $this->connection->beginTransaction();
        $this->entityManager->persist(new Article('a1', 'Draft'));
        $this->entityManager->persist(new Article('taken', 'Duplicate'));
        try {
            $this->entityManager->flush();
            self::fail('The flush should have failed.');
        } catch (UniqueConstraintViolationException) {
        }

        // As ManagerRegistry::resetManager() does with a lazy entity manager.
        self::resetInPlace($this->entityManager);
        $this->entityManager->persist(new Article('b1', 'Other'));
        $this->entityManager->flush();
        $this->connection->commit();

        self::assertSameMessages([new ArticlePublishedEvent('b1', 'Other')], $this->storedEvents());
    }

    public function test_the_entities_of_a_flush_that_failed_before_writing_are_collected_again(): void
    {
        $this->listen();
        $failing = $this->failingOnFlush();
        $this->connection->beginTransaction();
        $article = new Article('a1', 'Draft');
        $this->entityManager->persist($article);
        try {
            $this->entityManager->flush();
            self::fail('The flush should have failed.');
        } catch (\RuntimeException) {
        }
        $failing->fail = false;

        $this->entityManager->flush();
        $this->connection->commit();

        self::assertSameMessages([new ArticlePublishedEvent('a1', 'Draft')], $this->storedEvents());
        self::assertSame([], $article->recordedEvents());
    }

    public function test_a_clear_after_a_flush_that_failed_before_writing_and_was_rolled_back_starts_afresh(): void
    {
        $this->listen();
        $failing = $this->failingOnFlush();
        $this->connection->beginTransaction();
        $this->entityManager->persist(new Article('a1', 'Draft'));
        try {
            $this->entityManager->flush();
            self::fail('The flush should have failed.');
        } catch (\RuntimeException) {
        }
        $this->connection->rollBack();
        $this->entityManager->clear();
        $failing->fail = false;

        $this->entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager): void {
            $entityManager->persist(new Article('b1', 'Other'));
        });

        self::assertSameMessages([new ArticlePublishedEvent('b1', 'Other')], $this->storedEvents());
    }

    public function test_reset_forgets_the_entities_of_unfinished_flushes(): void
    {
        $this->listen();
        $failing = $this->failingOnFlush();
        $this->connection->beginTransaction();
        $this->entityManager->persist(new Article('a1', 'Draft'));
        try {
            $this->entityManager->flush();
            self::fail('The flush should have failed.');
        } catch (\RuntimeException) {
        }
        $this->entityManager->clear();
        $failing->fail = false;

        // Between the messages of a worker, or requests.
        $this->listener->reset();
        $this->entityManager->persist(new Article('b1', 'Other'));
        $this->entityManager->flush();
        $this->connection->commit();

        self::assertSameMessages([new ArticlePublishedEvent('b1', 'Other')], $this->storedEvents());
    }

    public function test_a_middleware_persisting_an_entity_while_the_events_are_stored_makes_the_transaction_rollback_only(): void
    {
        $this->middleware[] = $this->middleware(function (): void {
            $this->entityManager->persist(new Document('audit'));
        });
        $this->listen();
        $this->connection->beginTransaction();
        $this->entityManager->persist(new Article('a1', 'Draft'));

        $this->assertFailsAfterWrite('persisted or removed while the recorded events were stored', function (): void {
            $this->entityManager->flush();
        });
    }

    public function test_a_middleware_flushing_while_the_events_are_stored_makes_the_transaction_rollback_only(): void
    {
        $this->middleware[] = $this->middleware(function (): void {
            $this->entityManager->flush();
        });
        $this->listen();
        $this->connection->beginTransaction();
        $this->entityManager->persist(new Article('a1', 'Draft'));

        $this->assertFailsAfterWrite('flushed while the recorded events of its last flush were stored', function (): void {
            $this->entityManager->flush();
        });
    }

    public function test_a_middleware_swallowing_the_failure_of_its_flush_while_the_events_are_stored_still_fails(): void
    {
        $this->middleware[] = $this->middleware(function (): void {
            try {
                $this->entityManager->flush();
            } catch (\LogicException) {
            }
        });
        $this->listen();
        $this->connection->beginTransaction();
        $this->entityManager->persist(new Article('a1', 'Draft'));

        $this->assertFailsAfterWrite('flushed while the recorded events of its last flush were stored', function (): void {
            $this->entityManager->flush();
        });
    }

    public function test_a_middleware_clearing_the_entity_manager_while_the_events_are_stored_makes_the_transaction_rollback_only(): void
    {
        $this->middleware[] = $this->middleware(function (): void {
            $this->entityManager->clear();
        });
        $this->listen();
        $this->connection->beginTransaction();
        $this->entityManager->persist(new Article('a1', 'Draft'));

        $this->assertFailsAfterWrite('cleared, closed or reset while the recorded events were stored', function (): void {
            $this->entityManager->flush();
        });
    }

    public function test_an_entity_recording_while_its_events_are_stored_makes_the_transaction_rollback_only(): void
    {
        $article = new Article('a1', 'Draft');
        $recorded = false;
        $this->middleware[] = $this->middleware(static function () use ($article, &$recorded): void {
            if (!$recorded) {
                $recorded = true;
                $article->record(new ArticleFeaturedEvent('a1'));
            }
        });
        $this->listen();
        $this->connection->beginTransaction();
        $this->entityManager->persist($article);

        $this->assertFailsAfterWrite('recorded or released events while they were stored', function (): void {
            $this->entityManager->flush();
        });
    }

    public function test_an_event_bus_that_does_not_store_the_events_makes_the_transaction_rollback_only(): void
    {
        $this->eventBus = new class implements EventBusInterface {
            public function dispatch(Event $event, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope
            {
                return new Envelope($event);
            }

            public function dispatchSync(Event $event, StampInterface ...$stamps): Envelope
            {
                return new Envelope($event);
            }

            public function dispatchAsync(Event $event, StampInterface ...$stamps): Envelope
            {
                return new Envelope($event);
            }
        };
        $this->listen();
        $this->connection->beginTransaction();
        $article = new Article('a1', 'Draft');
        $this->entityManager->persist($article);

        $this->assertFailsAfterWrite('has no OutboxStoredStamp', function (): void {
            $this->entityManager->flush();
        });
        self::assertCount(1, $article->recordedEvents(), 'Events are released once they are all stored.');
    }

    public function test_a_missing_outbox_table_fails_the_flush_and_makes_the_transaction_rollback_only(): void
    {
        // A table that was never set up: the automatic setup never runs inside a transaction.
        $this->storage = new DbalOutboxStorage($this->connection, 'app_outbox');
        $this->listen();
        $this->connection->beginTransaction();
        $this->entityManager->persist(new Article('a1', 'Draft'));

        try {
            $this->entityManager->flush();
            self::fail('The store should have failed.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('The outbox table "app_outbox" does not exist', $exception->getMessage());
        }

        self::assertTrue($this->connection->isRollbackOnly());
        self::assertFalse($this->entityManager->isOpen());
        try {
            $this->connection->commit();
            self::fail('The commit should have failed.');
        } catch (CommitFailedRollbackOnly) {
        }
        $this->connection->rollBack();
        self::assertSame(0, $this->rowCount('cqrs_test_article'));
    }

    public function test_a_change_the_version_column_rejects_stores_no_events(): void
    {
        $this->listen();
        $this->entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager): void {
            $entityManager->persist(new Account('acc'));
        });
        $account = $this->entityManager->find(Account::class, 'acc');
        self::assertInstanceOf(Account::class, $account);
        // A concurrent change.
        $this->connection->executeStatement('UPDATE cqrs_test_account SET version = version + 1');
        $account->credit(10);

        try {
            $this->entityManager->wrapInTransaction(static function (): void {
            });
            self::fail('The flush should have failed.');
        } catch (OptimisticLockException) {
        }

        self::assertSame(0, $this->rowCount('somework_cqrs_outbox'));
    }

    public function test_an_entity_numbering_its_own_events(): void
    {
        $this->listen();
        $account = new Account('acc');
        $account->credit(10);
        $account->credit(5);
        $this->entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager) use ($account): void {
            $entityManager->persist($account);
        });
        $this->entityManager->wrapInTransaction(static function () use ($account): void {
            $account->credit(1);
        });

        self::assertSameMessages([
            new AccountCreditedEvent('acc', 10, 1),
            new AccountCreditedEvent('acc', 5, 2),
            new AccountCreditedEvent('acc', 1, 3),
        ], $this->storedEvents());
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT sequence FROM cqrs_test_account'));
    }

    public function test_a_constraint_that_fails_at_commit_rolls_the_rows_back(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('Deferred constraints are a PostgreSQL feature.');
        }
        $this->connection->executeStatement('CREATE TABLE cqrs_test_deferred (id INT NOT NULL, code VARCHAR(10) NOT NULL, PRIMARY KEY (id), CONSTRAINT cqrs_test_deferred_code UNIQUE (code) DEFERRABLE INITIALLY DEFERRED)');
        $this->listen();
        $article = new Article('a1', 'Draft');
        $this->connection->beginTransaction();
        $this->connection->insert('cqrs_test_deferred', ['id' => 1, 'code' => 'same']);
        $this->connection->insert('cqrs_test_deferred', ['id' => 2, 'code' => 'same']);
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        try {
            $this->connection->commit();
            self::fail('The commit should have failed.');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertSame(0, $this->rowCount('somework_cqrs_outbox'));
        self::assertSame(0, $this->rowCount('cqrs_test_article'));
        self::assertSame([], $article->recordedEvents(), 'The events were released once stored: re-run the operation.');
    }

    public function test_an_entity_manager_on_another_connection_is_refused_before_its_flush_writes(): void
    {
        $this->outboxConnection = TestDatabase::connect(keepTables: true);
        $this->listen();
        $article = new Article('a1', 'Draft');

        try {
            $this->entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager) use ($article): void {
                $entityManager->persist($article);
            });
            self::fail('The flush should have been refused.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('is not on the outbox connection', $exception->getMessage());
            self::assertStringContainsString(Article::class, $exception->getMessage());
        }

        self::assertSame(0, $this->rowCount('cqrs_test_article'));
        self::assertCount(1, $article->recordedEvents());
    }

    public function test_entity_managers_sharing_the_outbox_connection_store_their_events(): void
    {
        $this->listen();
        $other = TestEntityManager::create($this->connection, $this->events);

        $this->connection->transactional(function () use ($other): void {
            $this->entityManager->persist(new Article('a1', 'Draft'));
            $this->entityManager->flush();
            $other->persist(new Article('b1', 'Other'));
            $other->flush();
        });

        self::assertSameMessages([new ArticlePublishedEvent('a1', 'Draft'), new ArticlePublishedEvent('b1', 'Other')], $this->storedEvents());
    }

    public function test_a_handler_on_a_bus_with_the_dbal_transaction_middleware_of_symfony_8_2_stores_the_events_of_its_flush(): void
    {
        // DoctrineBridge 8.2: a transaction around the handlers, without a flush.
        $class = 'Symfony\\Bridge\\Doctrine\\Messenger\\DoctrineDbalTransactionMiddleware';
        if (!class_exists($class)) {
            self::markTestSkipped('DoctrineDbalTransactionMiddleware needs symfony/doctrine-bridge 8.2.');
        }
        $this->listen();
        $registry = $this->createMock(ConnectionRegistry::class);
        $registry->method('getConnection')->willReturn($this->connection);
        $transaction = new $class($registry);
        self::assertInstanceOf(MiddlewareInterface::class, $transaction);
        $flushingHandler = $this->middleware(function (): void {
            $this->entityManager->persist(new Article('a1', 'Draft'));
            $this->entityManager->flush();
        });
        $failingHandler = $this->middleware(static function (): void {
            throw new \RuntimeException('The handler failed after its flush.');
        });

        (new MessageBus([$transaction, $flushingHandler]))->dispatch(new \stdClass());

        self::assertSameMessages([new ArticlePublishedEvent('a1', 'Draft')], $this->storedEvents());

        $this->entityManager->clear();
        $this->connection->executeStatement('DELETE FROM cqrs_test_article');
        $this->connection->executeStatement('DELETE FROM somework_cqrs_outbox');
        try {
            (new MessageBus([$transaction, $flushingHandler, $failingHandler]))->dispatch(new \stdClass());
            self::fail('The handler should have failed.');
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $this->rowCount('cqrs_test_article'));
        self::assertSame([], $this->storedEvents());
    }

    private function listen(): void
    {
        $this->listener = new RecordedEventsListener(
            fn (): RecordedEventsPublisher => new RecordedEventsPublisher($this->eventBus ?? $this->eventBus(), $this->connection),
            $this->outboxConnection ?? $this->connection,
        );
        $this->events->addEventListener([Events::onFlush, Events::postFlush, Events::onClear], $this->listener);
    }

    /**
     * The event bus of the application: the outbox middleware around $this->middleware.
     */
    private function eventBus(): EventBus
    {
        $writer = new OutboxWriter($this->storage, new PhpSerializer(), transaction: $this->storage, requireTransaction: $this->requireTransaction);
        $handling = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new \LogicException('The event should have been stored, not handled.');
            }
        };

        return new EventBus(new MessageBus([new OutboxPrepareMiddleware(), ...$this->middleware, new OutboxStoreMiddleware($writer), $handling]), outbox: $writer);
    }

    /**
     * @param \Closure(): void $callback
     */
    private function middleware(\Closure $callback): MiddlewareInterface
    {
        return new class($callback) implements MiddlewareInterface {
            /**
             * @param \Closure(): void $callback
             */
            public function __construct(private readonly \Closure $callback)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->callback)();

                return $stack->next()->handle($envelope, $stack);
            }
        };
    }

    /**
     * Registers a postFlush listener that runs before the bundle's one, once.
     *
     * @param \Closure(EntityManagerInterface): void $callback
     */
    private function onPostFlush(\Closure $callback): void
    {
        $this->events->addEventListener([Events::postFlush], new class($callback) {
            private bool $done = false;

            /**
             * @param \Closure(EntityManagerInterface): void $callback
             */
            public function __construct(private readonly \Closure $callback)
            {
            }

            public function postFlush(PostFlushEventArgs $args): void
            {
                if (!$this->done) {
                    $this->done = true;
                    ($this->callback)($args->getObjectManager());
                }
            }
        });
    }

    /**
     * Registers an onFlush listener after the bundle's one, which fails until told otherwise.
     */
    private function failingOnFlush(): FailingOnFlushListener
    {
        $listener = new FailingOnFlushListener();
        $this->events->addEventListener([Events::onFlush], $listener);

        return $listener;
    }

    /**
     * @param \Closure(): void $flush
     * @param int              $articles The articles committed before the transaction
     */
    private function assertFailsAfterWrite(string $message, \Closure $flush, int $articles = 0): void
    {
        try {
            $flush();
            self::fail('The flush should have failed.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }

        self::assertTrue($this->connection->isRollbackOnly(), 'The transaction is rollback-only.');
        self::assertFalse($this->entityManager->isOpen(), 'The entity manager is closed.');
        try {
            $this->connection->commit();
            self::fail('The commit should have failed.');
        } catch (CommitFailedRollbackOnly) {
        }
        $this->connection->rollBack();
        self::assertSame(0, $this->rowCount('somework_cqrs_outbox'));
        self::assertSame($articles, $this->rowCount('cqrs_test_article'));
    }

    /**
     * @param \Closure(): void $operation
     */
    private static function thrown(\Closure $operation): \Throwable
    {
        try {
            $operation();
        } catch (\Throwable $exception) {
            return $exception;
        }

        self::fail('The operation should have failed.');
    }

    /**
     * Replaces the unit of work and reopens the entity manager, as ManagerRegistry::resetManager()
     * does with a lazy entity manager (the object stays the same).
     */
    private static function resetInPlace(EntityManagerInterface $entityManager): void
    {
        (new \ReflectionProperty(EntityManager::class, 'unitOfWork'))->setValue($entityManager, new UnitOfWork($entityManager));
        (new \ReflectionProperty(EntityManager::class, 'closed'))->setValue($entityManager, false);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    /**
     * @return list<object> The messages of the outbox rows, in the order they were stored
     */
    private function storedEvents(): array
    {
        $serializer = new PhpSerializer();

        return array_map(
            static fn (mixed $body): object => $serializer->decode(['body' => is_string($body) ? $body : ''])->getMessage(),
            $this->connection->fetchFirstColumn('SELECT body FROM somework_cqrs_outbox ORDER BY id'),
        );
    }
}
