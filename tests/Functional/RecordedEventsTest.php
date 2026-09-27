<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use SomeWork\CqrsBundle\Exception\OutboxRequiresTransactionException;
use SomeWork\CqrsBundle\Outbox\RecordedEventsPublisher;
use SomeWork\CqrsBundle\Stamp\AggregateSequenceStamp;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Testing\FakeEventBus;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Account;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Article;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\DoctrineEventsTestKernel;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticlePublishedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticleRenamedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AssertsMessages;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RenameArticleCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ReportFiledEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\EntityWritingMiddleware;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function array_map;
use function is_string;

/**
 * Entities of a real DoctrineBundle setup record events, which reach the outbox when the entity
 * manager flushes in the transaction of the "doctrine_transaction" middleware, and are relayed.
 */
#[Group('database')]
#[CoversNothing]
final class RecordedEventsTest extends KernelTestCase
{
    use AssertsMessages;

    protected static function getKernelClass(): string
    {
        return DoctrineEventsTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $this->createSchema();
    }

    public function test_the_events_of_a_command_handler_commit_with_its_changes_and_continue_its_flow(): void
    {
        $this->publishArticle();

        $command = $this->commandBus()->dispatch(new RenameArticleCommand('a1', 'Final'))->last(MessageMetadataStamp::class);

        self::assertInstanceOf(MessageMetadataStamp::class, $command);
        self::assertSame('Final', $this->connection()->fetchOne('SELECT title FROM cqrs_test_article'));
        $stored = $this->storedEnvelopes();
        self::assertCount(1, $stored);
        self::assertSameMessages([new ArticleRenamedEvent('a1', 'Final')], [$stored[0]->getMessage()]);
        $metadata = $stored[0]->last(MessageMetadataStamp::class);
        self::assertInstanceOf(MessageMetadataStamp::class, $metadata);
        self::assertSame($command->getCorrelationId(), $metadata->getCorrelationId(), 'The event continues the flow of the command.');
        self::assertSame($command->getMessageId(), $metadata->getCausationId(), 'The command caused the event.');
        self::assertSame('sync', $this->connection()->fetchOne('SELECT transport_name FROM somework_cqrs_outbox'));
    }

    public function test_a_handler_failing_after_its_flush_rolls_back_the_changes_with_their_events(): void
    {
        $this->publishArticle();

        try {
            $this->commandBus()->dispatch(new RenameArticleCommand('a1', 'Final', fail: true));
            self::fail('The handler should have failed.');
        } catch (HandlerFailedException) {
        }

        self::assertSame('Draft', $this->connection()->fetchOne('SELECT title FROM cqrs_test_article'));
        self::assertSame([], $this->storedEnvelopes());
    }

    public function test_the_relay_hands_the_events_to_their_handlers_whose_entities_record_the_next_ones(): void
    {
        $this->publishArticle();
        $command = $this->commandBus()->dispatch(new RenameArticleCommand('a1', 'Final'))->last(MessageMetadataStamp::class);
        self::assertInstanceOf(MessageMetadataStamp::class, $command);
        $renamed = $this->storedEnvelopes()[0]->last(MessageMetadataStamp::class);
        self::assertInstanceOf(MessageMetadataStamp::class, $renamed);

        $relay = $this->console('somework:cqrs:outbox:relay');

        self::assertSame(Command::SUCCESS, $relay->getStatusCode(), $relay->getDisplay());
        // The handler of ArticleRenamedEvent (sync://, in the relay) filed a report, whose event was
        // stored in the transaction of that handling, and relayed in the same run.
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM cqrs_test_document WHERE id = 'report-a1'"));
        self::assertSame([], $this->storedEnvelopes(), 'Both rows are relayed.');
        $rows = $this->storedEnvelopes(published: true);
        self::assertCount(2, $rows);
        self::assertSameMessages([new ReportFiledEvent('report-a1')], [$rows[1]->getMessage()]);
        $metadata = $rows[1]->last(MessageMetadataStamp::class);
        self::assertInstanceOf(MessageMetadataStamp::class, $metadata);
        self::assertSame($command->getCorrelationId(), $metadata->getCorrelationId());
        self::assertSame($renamed->getMessageId(), $metadata->getCausationId(), 'The relayed event caused the next one.');
    }

    public function test_an_entity_numbering_its_events_gives_them_a_sequence(): void
    {
        $this->entityManager()->wrapInTransaction(static function (EntityManagerInterface $entityManager): void {
            $account = new Account('acc');
            $account->credit(10);
            $account->credit(5);
            $entityManager->persist($account);
        });

        self::assertSameMessages([
            new AggregateSequenceStamp('acc', 1, 'account'),
            new AggregateSequenceStamp('acc', 2, 'account'),
        ], array_map(static fn (Envelope $envelope): ?AggregateSequenceStamp => $envelope->last(AggregateSequenceStamp::class), $this->storedEnvelopes()));
    }

    public function test_a_flush_outside_a_transaction_is_refused(): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist(new Article('a1', 'Draft'));

        $this->expectException(OutboxRequiresTransactionException::class);

        $entityManager->flush();
    }

    public function test_an_event_bus_middleware_writing_through_the_entity_manager_rolls_the_command_back(): void
    {
        $this->publishArticle();
        $middleware = self::getContainer()->get(EntityWritingMiddleware::class);
        self::assertInstanceOf(EntityWritingMiddleware::class, $middleware);
        $middleware->enabled = true;

        try {
            $this->commandBus()->dispatch(new RenameArticleCommand('a1', 'Final'));
            self::fail('The store should have failed.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('An entity was persisted or removed while the recorded events were stored', $exception->getMessage());
        }

        self::assertFalse($this->entityManager()->isOpen());
        self::assertSame('Draft', $this->connection()->fetchOne('SELECT title FROM cqrs_test_article'));
        self::assertSame([], $this->storedEnvelopes());
    }

    public function test_aggregates_without_the_orm_publish_their_events_in_the_transaction(): void
    {
        $publisher = self::getContainer()->get(RecordedEventsPublisher::class);
        self::assertInstanceOf(RecordedEventsPublisher::class, $publisher);
        // Not managed by the entity manager: a repository on DBAL writes it.
        $article = new Article('a1', 'Draft');

        $this->connection()->transactional(static function (Connection $connection) use ($publisher, $article): void {
            $connection->insert('cqrs_test_article', ['id' => $article->id(), 'title' => $article->title()]);
            $publisher->publish($article);
        });

        self::assertSame([], $article->recordedEvents());
        self::assertSameMessages([new ArticlePublishedEvent('a1', 'Draft')], array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->storedEnvelopes()));
    }

    public function test_the_fake_event_bus_receives_the_recorded_events(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel(['environment' => 'fake_event_bus']);
        $this->createSchema();

        $this->entityManager()->wrapInTransaction(static function (EntityManagerInterface $entityManager): void {
            $entityManager->persist(new Article('a1', 'Draft'));
        });

        $bus = self::getContainer()->get(FakeEventBus::class);
        self::assertInstanceOf(FakeEventBus::class, $bus);
        $dispatched = $bus->getDispatched();
        self::assertCount(1, $dispatched);
        self::assertSameMessages([new ArticlePublishedEvent('a1', 'Draft')], [$dispatched[0]->message]);
        self::assertSame(DispatchMode::OUTBOX, $dispatched[0]->mode);
        self::assertSame([], $this->storedEnvelopes());
    }

    private function publishArticle(): void
    {
        $this->entityManager()->wrapInTransaction(static function (EntityManagerInterface $entityManager): void {
            $entityManager->persist(new Article('a1', 'Draft'));
        });
        $this->connection()->executeStatement('DELETE FROM somework_cqrs_outbox');
        $this->entityManager()->clear();
    }

    /**
     * Creates the tables of the entities and the outbox (OutboxSchemaSubscriber adds it to the
     * schema of the ORM), after dropping those of an earlier test on a real database.
     */
    private function createSchema(): void
    {
        $entityManager = $this->entityManager();
        $tool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    /**
     * @param bool $published Whether to include the rows the relay published
     *
     * @return list<Envelope> The envelopes of the outbox rows not relayed yet, in the order they were stored
     */
    private function storedEnvelopes(bool $published = false): array
    {
        $serializer = self::getContainer()->get('somework_cqrs.outbox.serializer');
        self::assertInstanceOf(SerializerInterface::class, $serializer);

        return array_map(
            static fn (array $row): Envelope => $serializer->decode(['body' => is_string($row['body']) ? $row['body'] : '', 'headers' => []]),
            $this->connection()->fetchAllAssociative('SELECT body FROM somework_cqrs_outbox'.($published ? '' : ' WHERE published_at IS NULL').' ORDER BY id'),
        );
    }

    private function commandBus(): CommandBusInterface
    {
        $bus = self::getContainer()->get(CommandBusInterface::class);
        self::assertInstanceOf(CommandBusInterface::class, $bus);

        return $bus;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(string $command, array $input = []): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $tester = new CommandTester((new Application($kernel))->find($command));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }
}
