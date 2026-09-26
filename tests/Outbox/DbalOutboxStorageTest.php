<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Outbox\Dbal\DbalOutboxSchema;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\FailedOutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxStatus;
use SomeWork\CqrsBundle\Outbox\SetupLockLeftBehind;
use SomeWork\CqrsBundle\Outbox\Signing\OutboxSigner;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\BeforeQueryMiddleware;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\OutboxRows;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\QueryLog;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\TestDatabase;

use function array_filter;
use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function date_default_timezone_get;
use function date_default_timezone_set;
use function implode;
use function microtime;
use function preg_match_all;
use function preg_replace;
use function range;
use function sha1;
use function sprintf;
use function str_contains;
use function str_repeat;
use function str_starts_with;
use function strtoupper;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function time;
use function unlink;
use function usleep;

use const DATE_ATOM;

/**
 * Runs the storage against a real database: in-memory SQLite, or the one named by
 * CQRS_TEST_DATABASE_URL (see TestDatabase).
 */
#[Group('database')]
#[CoversClass(DbalOutboxStorage::class)]
#[CoversClass(DbalOutboxSchema::class)]
final class DbalOutboxStorageTest extends TestCase
{
    private const ID_1 = '00000000-0000-7000-8000-000000000001';

    private const ID_2 = '00000000-0000-7000-8000-000000000002';

    private const UNKNOWN_ID = 'ffffffff-ffff-7fff-bfff-ffffffffffff';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connect();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function test_stores_and_fetches_unpublished_messages_oldest_first(): void
    {
        $storage = new DbalOutboxStorage($this->connection);

        $storage->store(self::message('b0000000-0000-7000-8000-000000000002', '2026-01-01 10:00:00', 'async'));
        $storage->store(self::message('a0000000-0000-7000-8000-000000000001', '2026-01-01 10:00:00'));
        $storage->store(self::message('c0000000-0000-7000-8000-000000000003', '2026-01-01 09:00:00'));

        $messages = $storage->fetchUnpublished(10);

        // Oldest first per transport; the transports take turns.
        self::assertSame(
            ['c0000000-0000-7000-8000-000000000003', 'b0000000-0000-7000-8000-000000000002', 'a0000000-0000-7000-8000-000000000001'],
            array_map(static fn (OutboxMessage $message): string => $message->id, $messages),
        );
        self::assertSame('async', $messages[1]->transportName);
        self::assertNull($messages[2]->transportName);
        self::assertSame('2026-01-01 09:00:00', $messages[0]->createdAt->format('Y-m-d H:i:s'));
        self::assertSame('body', $messages[0]->body);
        self::assertSame('{"type":"test"}', $messages[0]->headers);
    }

    public function test_limit(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        foreach (['1', '2', '3'] as $minute) {
            $storage->store(self::message('00000000-0000-7000-8000-00000000000'.$minute, '2026-01-01 10:0'.$minute.':00'));
        }

        self::assertSame(['00000000-0000-7000-8000-000000000001', '00000000-0000-7000-8000-000000000002'], self::ids($storage->fetchUnpublished(2)));
    }

    public function test_a_fetch_reads_large_messages_up_to_a_budget(): void
    {
        // A batch of large messages must not exhaust the memory of the relay before it claims any of them.
        $storage = new DbalOutboxStorage($this->connection);
        foreach (['1' => 3, '2' => 3, '3' => 3, '4' => 9] as $minute => $megabytes) {
            $storage->store(new OutboxMessage('00000000-0000-7000-8000-00000000000'.$minute, str_repeat('x', $megabytes * 1024 * 1024), '{"type":"test"}', new DateTimeImmutable('2026-01-01 10:0'.$minute.':00')));
        }

        self::assertSame(['00000000-0000-7000-8000-000000000001', '00000000-0000-7000-8000-000000000002'], self::ids($storage->fetchUnpublished(10)));
        $storage->markPublished(['00000000-0000-7000-8000-000000000001']);
        $storage->markPublished(['00000000-0000-7000-8000-000000000002']);
        self::assertSame(['00000000-0000-7000-8000-000000000003'], self::ids($storage->fetchUnpublished(10)));
        $storage->markPublished(['00000000-0000-7000-8000-000000000003']);
        // A message larger than the budget is read on its own.
        self::assertSame(['00000000-0000-7000-8000-000000000004'], self::ids($storage->fetchUnpublished(10)));
    }

    public function test_a_failed_message_waits_for_its_retry_time(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:01:00'));

        OutboxRows::fail($storage, self::ID_1, 1, 'RuntimeException: boom', new DateTimeImmutable('+1 hour'));

        self::assertSame([self::ID_2], self::ids($storage->fetchUnpublished(10)), 'The failed message is skipped until its retry time.');

        OutboxRows::fail($storage, self::ID_2, 1, 'RuntimeException: boom', new DateTimeImmutable('-1 second'));
        $messages = $storage->fetchUnpublished(10);

        self::assertSame([self::ID_2], self::ids($messages));
        self::assertSame(1, $messages[0]->attempts);
    }

    public function test_a_message_that_failed_queues_up_again_behind_the_messages_stored_before_its_retry_time(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:01:00'));

        OutboxRows::fail($storage, self::ID_1, 1, 'RuntimeException: boom', new DateTimeImmutable('2026-01-01 10:05:00+00:00'));

        self::assertSame([self::ID_2, self::ID_1], self::ids($storage->fetchUnpublished(10)));
        self::assertSame([self::ID_2], self::ids($storage->fetchUnpublished(1)));
    }

    public function test_new_messages_come_before_retries_which_are_ordered_by_retry_time(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        foreach (['1', '2', '3', '4'] as $minute) {
            $storage->store(self::message('00000000-0000-7000-8000-00000000000'.$minute, '2026-01-01 10:0'.$minute.':00'));
        }
        OutboxRows::fail($storage, '00000000-0000-7000-8000-000000000001', 1, 'boom', new DateTimeImmutable('2026-01-01 11:30:00+00:00'));
        OutboxRows::fail($storage, '00000000-0000-7000-8000-000000000002', 1, 'boom', new DateTimeImmutable('2026-01-01 11:00:00+00:00'));

        self::assertSame(
            ['00000000-0000-7000-8000-000000000003', '00000000-0000-7000-8000-000000000004', '00000000-0000-7000-8000-000000000002', '00000000-0000-7000-8000-000000000001'],
            self::ids($storage->fetchUnpublished(10)),
        );
        self::assertSame(['00000000-0000-7000-8000-000000000003', '00000000-0000-7000-8000-000000000004', '00000000-0000-7000-8000-000000000002'], self::ids($storage->fetchUnpublished(3)));
        self::assertSame(['00000000-0000-7000-8000-000000000003'], self::ids($storage->fetchUnpublished(1)));
    }

    public function test_messages_of_excluded_transports_are_skipped(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message('00000000-0000-7000-8000-000000000001', '2026-01-01 10:00:00'));
        $storage->store(self::message('00000000-0000-7000-8000-000000000002', '2026-01-01 10:01:00', 'async'));
        $storage->store(self::message('00000000-0000-7000-8000-000000000003', '2026-01-01 10:02:00', 'ext'));

        self::assertSame(['00000000-0000-7000-8000-000000000001', '00000000-0000-7000-8000-000000000002'], self::ids((new DbalOutboxStorage($this->connection))->fetchUnpublished(10, ['ext'])));
        self::assertSame(['00000000-0000-7000-8000-000000000002', '00000000-0000-7000-8000-000000000003'], self::ids((new DbalOutboxStorage($this->connection))->fetchUnpublished(10, [null])));
        self::assertSame(['00000000-0000-7000-8000-000000000002'], self::ids((new DbalOutboxStorage($this->connection))->fetchUnpublished(10, [null, 'ext'])));
        self::assertSame(['00000000-0000-7000-8000-000000000001'], self::ids((new DbalOutboxStorage($this->connection))->fetchUnpublished(10, ['async', 'ext'])));
        self::assertSame([], self::ids((new DbalOutboxStorage($this->connection))->fetchUnpublished(10, ['async', null, 'ext'])));
    }

    public function test_transports_take_turns_and_each_relays_its_new_messages_before_its_retries(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        // A backlog of "async" (e.g. after an outage), and a little traffic on the other transports.
        foreach (['1', '2', '3', '4'] as $minute) {
            $storage->store(self::message('00000000-0000-7000-8000-00000000000'.$minute, '2026-01-01 10:0'.$minute.':00', 'async'));
        }
        $storage->store(self::message('00000000-0000-7000-8000-000000000005', '2026-01-01 10:05:00', 'events'));
        $storage->store(self::message('00000000-0000-7000-8000-000000000006', '2026-01-01 10:06:00'));
        $storage->store(self::message('00000000-0000-7000-8000-000000000007', '2026-01-01 10:07:00', 'ext'));
        OutboxRows::fail($storage, '00000000-0000-7000-8000-000000000001', 1, 'boom', new DateTimeImmutable('2026-01-01 09:00:00+00:00'));

        // The transport whose next row has waited longest goes first: async (10:02), events (10:05),
        // the rows without a transport name (10:06), ext (10:07).
        self::assertSame(
            [
                '00000000-0000-7000-8000-000000000002', '00000000-0000-7000-8000-000000000005', '00000000-0000-7000-8000-000000000006', '00000000-0000-7000-8000-000000000007',
                '00000000-0000-7000-8000-000000000003',
                '00000000-0000-7000-8000-000000000004',
                '00000000-0000-7000-8000-000000000001',
            ],
            self::ids((new DbalOutboxStorage($this->connection))->fetchUnpublished(10)),
        );
        self::assertSame(['00000000-0000-7000-8000-000000000002', '00000000-0000-7000-8000-000000000005', '00000000-0000-7000-8000-000000000006'], self::ids((new DbalOutboxStorage($this->connection))->fetchUnpublished(3)));
        self::assertSame(
            ['00000000-0000-7000-8000-000000000002', '00000000-0000-7000-8000-000000000005', '00000000-0000-7000-8000-000000000003', '00000000-0000-7000-8000-000000000004', '00000000-0000-7000-8000-000000000001'],
            self::ids((new DbalOutboxStorage($this->connection))->fetchUnpublished(10, ['ext', null])),
        );
    }

    public function test_transport_names_that_a_collation_treats_as_equal_are_all_fetched(): void
    {
        // MySQL's default collations compare "async", "ASYNC" and "async " as equal.
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00', 'async'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:00:01', 'ASYNC'));
        $storage->store(self::message(self::UNKNOWN_ID, '2026-01-01 10:00:02', 'async '));

        $fetched = $storage->fetchUnpublished(10);

        self::assertEqualsCanonicalizing([self::ID_1, self::ID_2, self::UNKNOWN_ID], self::ids($fetched));
        self::assertSame(3, $storage->status()->due);
    }

    public function test_numeric_transport_names_and_many_transports_take_turns(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        // More transports than one statement reads (UNION ALL of 50), with names PHP turns into integer keys.
        for ($transport = 0; $transport < 60; ++$transport) {
            foreach ([0, 1] as $row) {
                $storage->store(self::message(sprintf('00000000-0000-7000-8000-%06d%06d', $transport, $row), sprintf('2026-01-01 10:%02d:%02d', $row, $transport), (string) (100 + $transport)));
            }
        }

        $fetched = $storage->fetchUnpublished(70);

        self::assertCount(70, $fetched);
        self::assertSame(array_map(static fn (int $transport): string => (string) (100 + $transport), range(0, 59)), array_map(static fn (OutboxMessage $message): ?string => $message->transportName, array_slice($fetched, 0, 60)), 'The first row of every transport, oldest first.');
        self::assertSame(['100', '101'], array_map(static fn (OutboxMessage $message): ?string => $message->transportName, array_slice($fetched, 60, 2)));
        self::assertSame('00000000-0000-7000-8000-000000000001', $fetched[60]->id, 'Then their second rows.');
    }

    public function test_a_row_claimed_by_another_relay_between_the_two_fetch_queries_is_left_out(): void
    {
        $beforeFullRead = new BeforeQueryMiddleware(' IN (');
        $this->connection = TestDatabase::connect(null, [$beforeFullRead]);
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00', 'async'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:01:00', 'async'));
        // The ids are chosen, then another relay claims the first row before it is read in full.
        $beforeFullRead->callback = static function () use ($storage): void {
            self::assertSame([self::ID_1], $storage->claim([self::message(self::ID_1, '2026-01-01 10:00:00', 'async')], [0 => new DateTimeImmutable('+1 minute')], 'relay-b'));
        };

        $messages = $storage->fetchUnpublished(10);

        self::assertNull($beforeFullRead->callback, 'The claim ran between the two queries.');
        self::assertSame([self::ID_2], self::ids($messages), 'Relay A must not claim the row again with its new attempt count.');
    }

    public function test_the_transport_whose_next_row_has_waited_longest_goes_first(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message('00000000-0000-7000-8000-000000000001', '2026-01-01 10:02:00', 'a'));
        $storage->store(self::message('00000000-0000-7000-8000-000000000002', '2026-01-01 10:01:00', 'b'));
        $storage->store(self::message('00000000-0000-7000-8000-000000000003', '2026-01-01 10:00:00', 'c'));

        self::assertSame(['c'], array_map(static fn (OutboxMessage $message): ?string => $message->transportName, $storage->fetchUnpublished(1)));

        // Once its row is served (here: postponed), the next transport comes first.
        OutboxRows::fail($storage, '00000000-0000-7000-8000-000000000003', 1, 'boom', new DateTimeImmutable('+1 minute'));
        self::assertSame(['b', 'a'], array_map(static fn (OutboxMessage $message): ?string => $message->transportName, $storage->fetchUnpublished(2)));
    }

    public function test_a_table_that_is_up_to_date_is_used_without_taking_the_setup_lock(): void
    {
        $queries = new QueryLog();
        $this->connection = TestDatabase::connect($queries);
        (new DbalOutboxStorage($this->connection))->setup();
        $queries->flush();

        // A new process (e.g. every PHP-FPM request) with the default auto setup.
        (new DbalOutboxStorage($this->connection))->store(self::message(self::ID_1, '2026-01-01 10:00:00'));

        $sql = implode("\n", $queries->flush());
        self::assertStringNotContainsString('advisory_lock', $sql);
        self::assertStringNotContainsString('GET_LOCK', $sql);
        self::assertStringContainsString('INSERT INTO somework_cqrs_outbox', $sql);
    }

    public function test_purging_never_changes_a_table_of_an_earlier_version_and_storing_adds_the_columns(): void
    {
        $queries = new QueryLog();
        $this->connection = TestDatabase::connect($queries);
        TestDatabase::createTableOfVersion04($this->connection);
        $queries->flush();

        $storage = new DbalOutboxStorage($this->connection);
        $storage->purgePublished(new DateTimeImmutable());

        self::assertDoesNotMatchRegularExpression('/ALTER TABLE|CREATE TABLE/', implode("\n", $queries->flush()), 'Purges do not wait for an upgrade.');
        self::assertSame('the columns attempts, available_at, failed_at, last_error, claim_token, claimed_at, signature are missing', $storage->pendingChanges()[0]);

        // Outside a transaction, the automatic setup adds the columns (not the index) before storing.
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        self::assertStringContainsString('ALTER TABLE', implode("\n", $queries->flush()));
        self::assertSame([self::ID_1], self::ids($storage->fetchUnpublished(10)));
    }

    public function test_storing_in_a_transaction_on_a_table_of_an_earlier_version_asks_for_the_setup(): void
    {
        TestDatabase::createTableOfVersion04($this->connection);
        $storage = new DbalOutboxStorage($this->connection);
        $this->connection->beginTransaction();

        try {
            $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
            self::fail('Expected storing to fail.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('lacks columns this version of the bundle needs', $exception->getMessage());
            self::assertStringContainsString('bin/console somework:cqrs:outbox:setup', $exception->getMessage());
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }

    public function test_without_the_index_of_this_version_one_query_fetches_along_the_index_of_04(): void
    {
        $queries = new QueryLog();
        $this->connection = TestDatabase::connect($queries);
        TestDatabase::createTableOfVersion04($this->connection);
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message('00000000-0000-7000-8000-000000000001', '2026-01-01 10:00:00', 'a'));
        $storage->store(self::message('00000000-0000-7000-8000-000000000002', '2026-01-01 10:00:01', 'b'));
        $storage->store(self::message('00000000-0000-7000-8000-000000000003', '2026-01-01 10:00:02'));
        $storage->store(self::message('00000000-0000-7000-8000-000000000004', '2026-01-01 10:00:03', 'a'));
        $storage->fetchUnpublished(1);
        OutboxRows::fail($storage, '00000000-0000-7000-8000-000000000004', 1, 'boom', new DateTimeImmutable('+1 hour'));
        $queries->flush();

        // The per-transport queries would each read every pending row of a big 0.4 table.
        self::assertSame(['00000000-0000-7000-8000-000000000001', '00000000-0000-7000-8000-000000000002', '00000000-0000-7000-8000-000000000003'], self::ids($storage->fetchUnpublished(10)));
        self::assertSame(['00000000-0000-7000-8000-000000000002'], self::ids($storage->fetchUnpublished(10, ['a', null])));
        self::assertSame(['00000000-0000-7000-8000-000000000002', '00000000-0000-7000-8000-000000000003'], self::ids($storage->fetchUnpublished(10, ['a'])));
        self::assertSame(['00000000-0000-7000-8000-000000000001', '00000000-0000-7000-8000-000000000002'], self::ids($storage->fetchUnpublished(10, [null])));

        // One query chooses the rows, then their sizes and the rows themselves are read by id.
        $sql = array_values(array_filter($queries->flush(), static fn (string $query): bool => str_contains($query, 'ORDER BY')));
        self::assertCount(4, $sql);
        $leading = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'published_at ASC, ' : '';
        foreach ($sql as $query) {
            self::assertStringEndsWith('ORDER BY '.$leading.'created_at ASC, id ASC LIMIT 10', $query);
        }

        // Once the setup command built it, the transports take turns again.
        $storage->setup();
        $storage->fetchUnpublished(10);
        self::assertStringContainsString('SELECT transport_name FROM', implode("\n", $queries->flush()));
    }

    public function test_without_automatic_setup_a_missing_index_is_noticed_too(): void
    {
        TestDatabase::createTableOfVersion04($this->connection);
        // The columns come from a migration, the index does not (yet).
        (new DbalOutboxStorage($this->connection))->fetchUnpublished(1);
        $queries = new QueryLog();
        $connection = TestDatabase::connect($queries, keepTables: true);
        if (TestDatabase::isSqlite($connection)) {
            self::markTestSkipped('In-memory SQLite has one database per connection.');
        }
        $storage = new DbalOutboxStorage($connection, autoSetup: false);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00', 'a'));

        self::assertSame([self::ID_1], self::ids($storage->fetchUnpublished(10)));
        self::assertStringNotContainsString('SELECT transport_name FROM', implode("\n", $queries->flush()));
        $connection->close();
    }

    public function test_the_relay_asks_for_the_pending_changes_without_looking_at_the_table_again(): void
    {
        $queries = new QueryLog();
        $this->connection = TestDatabase::connect($queries);
        TestDatabase::createTableOfVersion04($this->connection);
        $storage = new DbalOutboxStorage($this->connection);
        $storage->fetchUnpublished(10);
        $queries->flush();

        self::assertSame([
            'the index "idx_somework_cqrs_outbox_pending" is missing',
            'the index "idx_somework_cqrs_outbox_claimed" is missing',
            'the index "idx_somework_cqrs_outbox_published_created" of version 0.4 is still there',
        ], $storage->pendingChanges());
        self::assertSame([], $queries->flush(), 'The first use of the process just looked.');

        // Asked again, it looks again (e.g. after the setup command ran elsewhere).
        (new DbalOutboxStorage($this->connection))->setup();
        self::assertSame([], $storage->pendingChanges());
    }

    public function test_the_setup_command_refuses_a_pooler_in_transaction_mode(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('The setup takes a session lock of PostgreSQL.');
        }
        TestDatabase::createTableOfVersion04($this->connection);
        // Behind PgBouncer in transaction mode, two statements may run on different server connections.
        $pooler = new BeforeQueryMiddleware('pg_backend_pid()');
        $pooler->replacement = 'SELECT (extract(epoch from clock_timestamp()) * 1000000)::bigint';
        $connection = TestDatabase::connect(null, [$pooler], keepTables: true);

        try {
            (new DbalOutboxStorage($connection, autoSetup: false))->setup();
            self::fail('The setup lock would stay with another client.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('is not set up through a pooler in transaction mode (e.g. PgBouncer), which would hand the setup lock to other clients.', $exception->getMessage());
        } finally {
            $connection->close();
        }
    }

    public function test_the_setup_command_notices_a_pooler_that_handed_its_lock_to_another_connection(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('The setup takes a session lock of PostgreSQL.');
        }
        TestDatabase::createTableOfVersion04($this->connection);
        // The statement that releases the lock ran on another server connection, which does not hold it.
        $pooler = new BeforeQueryMiddleware('pg_advisory_unlock');
        $pooler->replacement = 'SELECT NULL WHERE CAST(? AS text) IS NOT NULL AND CAST(? AS text) IS NOT NULL AND CAST(? AS text) IS NOT NULL';
        $connection = TestDatabase::connect(null, [$pooler], keepTables: true);

        try {
            (new DbalOutboxStorage($connection, autoSetup: false))->setup();
            self::fail('The setup lock stays with another client.');
        } catch (SetupLockLeftBehind $exception) {
            self::assertStringContainsString('is set up; it ran through a pooler in transaction mode (e.g. PgBouncer): the setup lock (and possibly a statement_timeout of 0) stays with another server connection', $exception->getMessage());
        } finally {
            $connection->close();
        }
        self::assertSame([], (new DbalOutboxStorage($this->connection))->pendingChanges(), 'The setup itself was done.');
    }

    public function test_a_setup_refused_after_taking_the_lock_releases_it(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('The setup takes a session lock of PostgreSQL.');
        }
        TestDatabase::createTableOfVersion04($this->connection);
        // After the lock, the pooler hands the next statement to another server connection.
        $otherServerConnection = new BeforeQueryMiddleware('SELECT pg_backend_pid()');
        $lock = new BeforeQueryMiddleware('pg_try_advisory_lock');
        $lock->callback = static function () use ($otherServerConnection): void {
            $otherServerConnection->replacement = 'SELECT 0';
        };
        $connection = TestDatabase::connect(null, [$otherServerConnection, $lock], keepTables: true);

        try {
            (new DbalOutboxStorage($connection, autoSetup: false))->setup();
            self::fail('The pooler is refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('is not set up through a pooler in transaction mode', $exception->getMessage());
            self::assertNotInstanceOf(SetupLockLeftBehind::class, $exception);
        }

        self::assertTrue($this->connection->fetchOne('SELECT pg_try_advisory_lock(hashtext(?))', ['somework_cqrs_outbox_setup_'.substr(sha1('somework_cqrs_outbox'), 0, 16)]), 'The lock was released.');
        $connection->close();
    }

    public function test_a_setup_whose_connection_was_killed_reports_that_instead_of_a_pooler(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('The setup takes a session lock of PostgreSQL.');
        }
        TestDatabase::createTableOfVersion04($this->connection);
        // e.g. a DBA terminates the long index build: its session and its lock are gone.
        $build = new BeforeQueryMiddleware('CREATE INDEX CONCURRENTLY');
        $connection = TestDatabase::connect(null, [$build], keepTables: true);
        $build->callback = function () use ($connection): void {
            $this->connection->fetchOne('SELECT pg_terminate_backend(?)', [$connection->fetchOne('SELECT pg_backend_pid()')]);
        };

        try {
            (new DbalOutboxStorage($connection, autoSetup: false))->setup();
            self::fail('The build was interrupted.');
        } catch (\RuntimeException|DbalException $exception) {
            self::assertNotInstanceOf(SetupLockLeftBehind::class, $exception, $exception->getMessage());
        } finally {
            $connection->close();
        }

        (new DbalOutboxStorage($this->connection))->setup();
        self::assertSame([], (new DbalOutboxStorage($this->connection))->pendingChanges(), 'The next setup finishes the job.');
    }

    public function test_the_mysql_setup_lock_belongs_to_the_database_of_the_table(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('MySQL names its locks for the whole server.');
        }
        try {
            $this->connection->executeStatement('DROP TABLE IF EXISTS cqrs_test_other.somework_cqrs_outbox');
        } catch (DbalException $exception) {
            self::markTestSkipped('Needs the database "cqrs_test_other": '.$exception->getMessage());
        }
        $holder = TestDatabase::connect(keepTables: true);
        $holder->fetchOne('SELECT GET_LOCK(?, 0)', ['somework_cqrs_outbox_setup_'.substr(sha1('cqrs_test_other.somework_cqrs_outbox'), 0, 16)]);
        $waited = 0;

        try {
            (new DbalOutboxStorage($this->connection, 'cqrs_test_other.somework_cqrs_outbox'))->setup(static function () use (&$waited, $holder): void {
                ++$waited;
                $holder->close();
            });
        } finally {
            $holder->close();
        }

        self::assertSame(1, $waited, 'The setup of that table in another database waited.');
    }

    public function test_the_automatic_upgrade_of_mysql_only_adds_columns_instantly(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('MySQL and MariaDB can rebuild a table to add columns.');
        }
        TestDatabase::createTableOfVersion04($this->connection);
        // A compressed table is rebuilt to add columns, which takes long on a big one.
        $this->connection->executeStatement('ALTER TABLE somework_cqrs_outbox ROW_FORMAT=COMPRESSED');

        try {
            (new DbalOutboxStorage($this->connection))->fetchUnpublished(10);
            self::fail('The table is not rebuilt by the relay.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('which this database cannot add without rebuilding the table. Run "bin/console somework:cqrs:outbox:setup".', $exception->getMessage());
        }

        (new DbalOutboxStorage($this->connection))->setup();
        self::assertSame([], (new DbalOutboxStorage($this->connection))->fetchUnpublished(10));
    }

    public function test_an_invalid_index_is_not_used(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('Invalid indexes exist only on PostgreSQL (CREATE INDEX CONCURRENTLY).');
        }
        $queries = new QueryLog();
        $this->connection = TestDatabase::connect($queries);
        (new DbalOutboxStorage($this->connection))->setup();
        $this->connection->executeStatement("UPDATE pg_index SET indisvalid = false WHERE indexrelid = 'idx_somework_cqrs_outbox_pending'::regclass");
        $queries->flush();

        (new DbalOutboxStorage($this->connection, autoSetup: false))->fetchUnpublished(10);

        self::assertStringNotContainsString('SELECT transport_name FROM', implode("\n", $queries->flush()), 'The per-transport queries would read every pending row.');

        // A valid one is used.
        $this->connection->executeStatement("UPDATE pg_index SET indisvalid = true WHERE indexrelid = 'idx_somework_cqrs_outbox_pending'::regclass");
        (new DbalOutboxStorage($this->connection, autoSetup: false))->fetchUnpublished(10);
        self::assertStringContainsString('SELECT transport_name FROM', implode("\n", $queries->flush()));
    }

    public function test_a_mysql_table_of_a_database_is_used_on_a_connection_without_one(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Only MySQL qualifies table names with a database.');
        }
        $database = (string) $this->connection->getDatabase();
        (new DbalOutboxStorage($this->connection))->setup();
        $params = $this->connection->getParams();
        unset($params['dbname']);
        $connection = DriverManager::getConnection($params);

        try {
            $storage = new DbalOutboxStorage($connection, $database.'.somework_cqrs_outbox');
            $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));

            self::assertSame([self::ID_1], self::ids($storage->fetchUnpublished(10)));
        } finally {
            $connection->close();
        }
    }

    public function test_storing_looks_at_the_table_once_per_process(): void
    {
        $queries = new QueryLog();
        $this->connection = TestDatabase::connect($queries);
        TestDatabase::createTableOfVersion04($this->connection);
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $queries->flush();

        $storage->store(self::message(self::ID_2, '2026-01-01 10:00:01'));

        self::assertCount(1, $queries->flush(), 'Only the INSERT.');
    }

    public function test_the_automatic_upgrade_does_not_try_while_a_transaction_of_the_mysql_server_is_old(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform || $platform instanceof MariaDBPlatform) {
            self::markTestSkipped('MySQL does not tell which tables a transaction holds (MariaDB alters with NOWAIT instead).');
        }
        try {
            $this->connection->fetchOne('SELECT COUNT(*) FROM information_schema.innodb_trx');
        } catch (DbalException) {
            self::markTestSkipped('Needs the PROCESS privilege.');
        }
        TestDatabase::createTableOfVersion04($this->connection);
        // Start times are shown in the time zone of the server (CI runs it in another one than UTC).
        $this->connection->executeStatement("SET time_zone = '+00:00'");
        $other = TestDatabase::connect(keepTables: true);
        // e.g. a report; transaction start times are shown in whole seconds.
        $other->beginTransaction();
        $other->fetchOne('SELECT COUNT(*) FROM somework_cqrs_outbox');
        usleep(2_100_000);

        try {
            $storage = new DbalOutboxStorage($this->connection);
            try {
                $storage->fetchUnpublished(10);
                self::fail('The columns are not added while a transaction of the server is old.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('is not changed while a transaction of the database server has been open for more than 1 second(s)', $exception->getMessage());
            }
            self::assertSame('+00:00', $this->connection->fetchOne('SELECT @@session.time_zone'), 'The time zone of the session is restored.');

            // A missing table is still created: nobody can hold it.
            $new = new DbalOutboxStorage($this->connection, 'app_outbox');
            $new->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
            self::assertSame([self::ID_1], self::ids($new->fetchUnpublished(10)));
        } finally {
            $other->rollBack();
            $other->close();
        }

        // InnoDB refreshes information_schema.innodb_trx at most every 0.1 seconds.
        usleep(200_000);
        self::assertSame([], (new DbalOutboxStorage($this->connection))->fetchUnpublished(10), 'Once the transaction ended.');
    }

    public function test_the_setup_says_when_it_waits_for_another_one(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (TestDatabase::isSqlite($this->connection)) {
            self::markTestSkipped('SQLite takes no setup lock.');
        }
        TestDatabase::createTableOfVersion04($this->connection);
        $holder = TestDatabase::connect(keepTables: true);
        $name = 'somework_cqrs_outbox_setup_'.substr(sha1($platform instanceof PostgreSQLPlatform ? 'somework_cqrs_outbox' : $holder->getDatabase().'.somework_cqrs_outbox'), 0, 16);
        $holder->fetchOne($platform instanceof PostgreSQLPlatform ? 'SELECT pg_advisory_lock(hashtext(?))' : 'SELECT GET_LOCK(?, 0)', [$name]);
        $waited = 0;

        try {
            (new DbalOutboxStorage($this->connection))->setup(static function () use (&$waited, $holder): void {
                ++$waited;
                // The other setup finishes.
                $holder->close();
            });
        } finally {
            $holder->close();
        }

        self::assertSame(1, $waited);
        self::assertSame([], (new DbalOutboxStorage($this->connection))->pendingChanges());
    }

    public function test_the_automatic_upgrade_gives_up_at_once_behind_a_long_transaction(): void
    {
        if (TestDatabase::isSqlite($this->connection)) {
            self::markTestSkipped('Needs a second connection to the same database.');
        }
        $queries = new QueryLog();
        $this->connection = TestDatabase::connect($queries);
        TestDatabase::createTableOfVersion04($this->connection);
        $other = TestDatabase::connect(keepTables: true);
        // e.g. a report, a dump or a connection left idle in a transaction.
        $other->beginTransaction();
        $other->fetchOne('SELECT COUNT(*) FROM somework_cqrs_outbox');
        $platform = $this->connection->getDatabasePlatform();

        try {
            $storage = new DbalOutboxStorage($this->connection);

            $started = microtime(true);
            try {
                // Writes need the columns of this version (the signature): run the setup command before the deployment.
                $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
                self::fail('The columns cannot be added while the transaction holds the table.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('kept it locked for more than 1 second(s)', $exception->getMessage());
            }
            self::assertLessThan(2.5, microtime(true) - $started);
            // The change never waits in the lock queue, where the writes would queue behind it
            // (MySQL can only bound the wait).
            $sql = implode("\n", $queries->flush());
            if ($platform instanceof PostgreSQLPlatform) {
                self::assertStringContainsString('LOCK TABLE somework_cqrs_outbox IN ACCESS EXCLUSIVE MODE NOWAIT', $sql);
                self::assertStringNotContainsString('ALTER TABLE', $sql);
            } elseif ($platform instanceof MariaDBPlatform) {
                self::assertStringContainsString('ALTER TABLE somework_cqrs_outbox NOWAIT ADD attempts', $sql);
            } else {
                self::assertStringContainsString('SET SESSION lock_wait_timeout = 1', $sql);
            }
        } finally {
            $other->rollBack();
            $other->close();
        }

        // Once the transaction is over, the next write upgrades the table.
        $storage->store(self::message(self::ID_2, '2026-01-01 10:00:01'));
        self::assertSame([self::ID_2], self::ids((new DbalOutboxStorage($this->connection))->fetchUnpublished(10)));
    }

    public function test_a_process_waiting_for_another_setup_goes_on_once_the_columns_exist(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (TestDatabase::isSqlite($this->connection)) {
            self::markTestSkipped('SQLite takes no setup lock.');
        }
        $postgres = $platform instanceof PostgreSQLPlatform;
        $middleware = new BeforeQueryMiddleware($postgres ? 'pg_try_advisory_xact_lock' : 'GET_LOCK');
        $this->connection = TestDatabase::connect(null, [$middleware]);
        TestDatabase::createTableOfVersion04($this->connection);
        $other = TestDatabase::connect(keepTables: true);
        // Right before this process tries the lock, another one takes it (and keeps it, e.g. to build
        // the index next) and adds the columns.
        $middleware->callback = static function () use ($other, $postgres): void {
            $name = 'somework_cqrs_outbox_setup_'.substr(sha1($postgres ? 'somework_cqrs_outbox' : $other->getDatabase().'.somework_cqrs_outbox'), 0, 16);
            $other->fetchOne($postgres ? 'SELECT pg_advisory_lock(hashtext(?))' : 'SELECT GET_LOCK(?, 0)', [$name]);
            (new DbalOutboxStorage($other))->fetchUnpublished(1);
        };

        try {
            $started = microtime(true);
            $storage = new DbalOutboxStorage($this->connection);

            self::assertSame([], $storage->fetchUnpublished(10));
            self::assertNull($middleware->callback, 'The other process held the lock.');
            self::assertLessThan(5, microtime(true) - $started, 'It did not wait for the lock that it no longer needs.');
        } finally {
            $other->close();
        }
    }

    public function test_setup_leaves_an_index_that_another_process_builds_alone(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('Only PostgreSQL builds indexes concurrently.');
        }
        (new DbalOutboxStorage($this->connection))->setup();
        // A build in progress is not valid yet; pg_stat_progress_create_index reports it.
        $this->connection->executeStatement("UPDATE pg_index SET indisvalid = false WHERE indexrelid = 'idx_somework_cqrs_outbox_pending'::regclass");
        $progress = new BeforeQueryMiddleware('pg_stat_progress_create_index');
        $progress->replacement = "SELECT 'idx_somework_cqrs_outbox_pending' AS relname WHERE CAST(? AS text) IS NOT NULL";
        $connection = TestDatabase::connect(null, [$progress], keepTables: true);
        $storage = new DbalOutboxStorage($connection);
        // e.g. a setup killed during the build, whose server process goes on building and holds the lock.
        $holder = TestDatabase::connect(keepTables: true);
        $holder->fetchOne('SELECT pg_advisory_lock(hashtext(?))', ['somework_cqrs_outbox_setup_'.substr(sha1('somework_cqrs_outbox'), 0, 16)]);
        $started = microtime(true);

        self::assertSame(['the index "idx_somework_cqrs_outbox_pending" is being built'], $storage->pendingChanges());
        try {
            $storage->setup();
            self::fail('The build of the other process is not dropped.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Another process is building the index "idx_somework_cqrs_outbox_pending" of the outbox table "somework_cqrs_outbox". Run "bin/console somework:cqrs:outbox:setup" again once it has finished.', $exception->getMessage());
            self::assertLessThan(5, microtime(true) - $started, 'It says so instead of waiting for the lock.');
        } finally {
            $connection->close();
            $holder->close();
        }
        self::assertTrue(TestDatabase::hasIndex($this->connection, 'somework_cqrs_outbox', 'idx_somework_cqrs_outbox_pending'));
    }

    public function test_setup_rebuilds_an_index_that_a_killed_build_left_invalid(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('Invalid indexes exist only on PostgreSQL (CREATE INDEX CONCURRENTLY).');
        }

        $storage = new DbalOutboxStorage($this->connection);
        $storage->setup();
        // What a CREATE INDEX CONCURRENTLY that was killed leaves behind.
        $this->connection->executeStatement("UPDATE pg_index SET indisvalid = false WHERE indexrelid = 'idx_somework_cqrs_outbox_pending'::regclass");

        (new DbalOutboxStorage($this->connection))->setup();

        self::assertTrue($this->connection->fetchOne("SELECT indisvalid FROM pg_index WHERE indexrelid = 'idx_somework_cqrs_outbox_pending'::regclass"));
    }

    public function test_a_qualified_table_name_works(): void
    {
        $qualifier = match (true) {
            $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform => 'public',
            TestDatabase::isSqlite($this->connection) => null,
            default => $this->connection->getDatabase(),
        };
        if (null === $qualifier) {
            self::markTestSkipped('SQLite has no schemas to qualify the table name with.');
        }

        $this->assertQualifiedTableNameWorks($qualifier.'.app_outbox');
    }

    public function test_a_table_in_another_database_of_mysql_works(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Only MySQL qualifies table names with a database.');
        }

        try {
            $this->connection->executeStatement('DROP TABLE IF EXISTS cqrs_test_other.app_outbox');
        } catch (DbalException $exception) {
            self::markTestSkipped('Needs the database "cqrs_test_other": '.$exception->getMessage());
        }
        $database = $this->connection->getDatabase();

        $this->assertQualifiedTableNameWorks('cqrs_test_other.app_outbox');

        self::assertSame($database, $this->connection->getDatabase(), 'The connection is back on its own database.');
    }

    public function test_the_automatic_setup_adds_the_columns_and_leaves_the_indexes_to_the_setup_command(): void
    {
        $queries = new QueryLog();
        $this->connection = TestDatabase::connect($queries);
        TestDatabase::createTableOfVersion04($this->connection);
        $queries->flush();

        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));

        self::assertSame([self::ID_1], self::ids($storage->fetchUnpublished(10)));
        self::assertDoesNotMatchRegularExpression('/(CREATE|DROP) INDEX|ADD INDEX/', implode("\n", $queries->flush()), 'Building an index takes long on a big table.');
        self::assertSame([
            'the index "idx_somework_cqrs_outbox_pending" is missing',
            'the index "idx_somework_cqrs_outbox_claimed" is missing',
            'the index "idx_somework_cqrs_outbox_published_created" of version 0.4 is still there',
        ], $storage->pendingChanges());

        $queries->flush();
        $storage->setup();

        self::assertSame([], $storage->pendingChanges());
        self::assertTrue(TestDatabase::hasIndex($this->connection, 'somework_cqrs_outbox', 'idx_somework_cqrs_outbox_pending'));
        // The relay is never left without an index: the old one goes once the new one exists (SQLite
        // rebuilds the table instead).
        $sql = implode("\n", $queries->flush());
        if (!TestDatabase::isSqlite($this->connection)) {
            self::assertSame(1, preg_match_all('/DROP INDEX/', $sql), 'Only the index of 0.4 is dropped.');
            self::assertMatchesRegularExpression('/CREATE INDEX (CONCURRENTLY (IF NOT EXISTS )?)?idx_somework_cqrs_outbox_pending.*DROP INDEX (CONCURRENTLY (IF EXISTS )?)?`?idx_somework_cqrs_outbox_published_created/s', $sql);
        }
    }

    public function test_setting_up_the_table_leaves_the_session_settings_of_the_connection_as_they_were(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        [$settings, $set] = match (true) {
            $platform instanceof PostgreSQLPlatform => [['SHOW lock_timeout', 'SHOW statement_timeout'], ["SET lock_timeout = '42s'", "SET statement_timeout = '42s'"]],
            $platform instanceof AbstractMySQLPlatform => [['SELECT @@SESSION.lock_wait_timeout'], ['SET SESSION lock_wait_timeout = 42']],
            default => [[], []],
        };
        if ([] === $settings) {
            self::markTestSkipped('SQLite has no lock timeouts.');
        }
        $queries = new QueryLog();
        $this->connection = TestDatabase::connect($queries);
        TestDatabase::createTableOfVersion04($this->connection);
        foreach ($set as $statement) {
            $this->connection->executeStatement($statement);
        }
        $before = array_map(fn (string $sql): mixed => $this->connection->fetchOne($sql), $settings);
        $queries->flush();

        // The automatic setup adds the columns (for the relay), the setup command builds the index.
        (new DbalOutboxStorage($this->connection))->fetchUnpublished(1);
        $automatic = implode("\n", $queries->flush());
        (new DbalOutboxStorage($this->connection))->setup();
        $explicit = implode("\n", $queries->flush());

        self::assertSame($before, array_map(fn (string $sql): mixed => $this->connection->fetchOne($sql), $settings));
        if ($platform instanceof PostgreSQLPlatform) {
            // Behind a pooler in transaction mode, only what ends with the transaction stays with this client.
            self::assertStringContainsString('pg_try_advisory_xact_lock', $automatic);
            self::assertStringContainsString("SET LOCAL lock_timeout = '1s'", $automatic);
            self::assertStringNotContainsString('SET lock_timeout', $automatic);
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"));
            // A statement timeout of the role would cancel the build of the index on a big table.
            self::assertMatchesRegularExpression("/set_config\\('statement_timeout', '0', false\\)\\) s\\s+CREATE INDEX CONCURRENTLY/", $explicit);
        } else {
            self::assertStringContainsString('SET SESSION lock_wait_timeout = 1', $automatic);
            self::assertStringContainsString('SET SESSION lock_wait_timeout = 5', $explicit);
            // In short waits, so that a second signal stops the process.
            self::assertStringContainsString('GET_LOCK(?, 1)', $explicit);
        }
    }

    public function test_setup_finds_an_invalid_index_along_the_search_path(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('Invalid indexes exist only on PostgreSQL (CREATE INDEX CONCURRENTLY).');
        }

        $storage = new DbalOutboxStorage($this->connection);
        $storage->setup();
        // The table is in "public", the first schema of the search path is another one.
        $this->connection->executeStatement('DROP SCHEMA IF EXISTS cqrs_test_first CASCADE');
        $this->connection->executeStatement('CREATE SCHEMA cqrs_test_first');
        $this->connection->executeStatement('SET search_path = cqrs_test_first, public');
        $this->connection->executeStatement("UPDATE pg_index SET indisvalid = false WHERE indexrelid = 'public.idx_somework_cqrs_outbox_pending'::regclass");

        self::assertSame(['the index "idx_somework_cqrs_outbox_pending" is invalid (its build was interrupted)'], $storage->pendingChanges());
        (new DbalOutboxStorage($this->connection))->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('cqrs_test_first.somework_cqrs_outbox')"), 'The table is found, not created again in the first schema.');
        (new DbalOutboxStorage($this->connection))->setup();

        self::assertTrue($this->connection->fetchOne("SELECT indisvalid FROM pg_index WHERE indexrelid = 'public.idx_somework_cqrs_outbox_pending'::regclass"));
        $this->connection->executeStatement('DROP SCHEMA cqrs_test_first CASCADE');
    }

    public function test_transports_whose_next_rows_tie_take_turns_across_fetches(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00', 'a'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:00:00', 'b'));

        $first = $storage->fetchUnpublished(1);
        $second = $storage->fetchUnpublished(1);

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertNotSame($first[0]->transportName, $second[0]->transportName, 'The rows were left as they were, yet the other transport comes first.');
    }

    public function test_fetch_queries_are_ordered_along_an_index(): void
    {
        $queries = new QueryLog();
        $this->connection = TestDatabase::connect($queries);
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00', 'async'));
        $queries->flush();

        $storage->fetchUnpublished(10);
        $storage->fetchUnpublished(10, ['ext']);

        // PostgreSQL only walks an index when the columns restricted to one value are part of the
        // ORDER BY; MySQL sorts when they are. Otherwise every fetch sorts the whole backlog.
        $leading = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $sorted = array_filter($queries->flush(), static fn (string $sql): bool => str_contains($sql, 'ORDER BY'));
        $orders = array_map(static fn (string $sql): string => (string) preg_replace('/^.*ORDER BY (.*?)( LIMIT.*)?$/s', '$1', $sql), $sorted);
        $expected = [
            ($leading ? 'published_at ASC, failed_at ASC, ' : '').'transport_name ASC',
            ($leading ? 'published_at ASC, failed_at ASC, transport_name ASC, available_at ASC, ' : '').'created_at ASC, id ASC',
            ($leading ? 'published_at ASC, failed_at ASC, transport_name ASC, ' : '').'available_at ASC, created_at ASC, id ASC',
        ];
        self::assertSame(array_values(array_unique($expected)), array_values(array_unique($orders)));
    }

    public function test_setup_replaces_an_index_of_version_04_whose_name_was_cut_to_63_characters(): void
    {
        TestDatabase::createTableOfVersion04($this->connection, TestDatabase::LONG_TABLE_NAME);
        $legacy = substr('idx_'.TestDatabase::LONG_TABLE_NAME.'_published_created', 0, 63);
        self::assertTrue(TestDatabase::hasIndex($this->connection, TestDatabase::LONG_TABLE_NAME, $legacy));

        (new DbalOutboxStorage($this->connection, TestDatabase::LONG_TABLE_NAME, autoSetup: false))->setup();

        self::assertFalse(TestDatabase::hasIndex($this->connection, TestDatabase::LONG_TABLE_NAME, $legacy));
    }

    public function test_the_number_of_attempts_is_stored_as_given(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));

        // The relay records an attempt before sending it, and again with the error when it fails.
        OutboxRows::fail($storage, self::ID_1, 1, 'interrupted', new DateTimeImmutable('-1 minute'));
        OutboxRows::fail($storage, self::ID_1, 1, 'first', new DateTimeImmutable('-1 minute'));
        OutboxRows::fail($storage, self::ID_1, 2, 'second', new DateTimeImmutable('-1 minute'));

        self::assertSame(2, $storage->fetchUnpublished(1)[0]->attempts);
    }

    public function test_a_given_up_message_is_listed_and_can_be_requeued(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00', 'async'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:01:00'));
        OutboxRows::fail($storage, self::ID_1, 1, 'first failure', new DateTimeImmutable('-1 minute'));
        OutboxRows::fail($storage, self::ID_1, 2, 'MessageDecodingFailedException: gone', null);
        OutboxRows::fail($storage, self::ID_2, 1, 'RuntimeException: boom', null);

        self::assertSame([], self::ids($storage->fetchUnpublished(10)), 'Given-up messages are no longer due.');

        $failed = $storage->fetchFailed(10);
        self::assertSame([self::ID_1, self::ID_2], array_map(static fn (FailedOutboxMessage $message): string => $message->id, $failed));
        self::assertSame(2, $failed[0]->attempts);
        self::assertSame('MessageDecodingFailedException: gone', $failed[0]->lastError);
        self::assertSame('async', $failed[0]->transportName);
        self::assertSame('2026-01-01T10:00:00+00:00', $failed[0]->createdAt->format(DATE_ATOM));
        self::assertEqualsWithDelta(time(), $failed[0]->failedAt->getTimestamp(), 5);

        self::assertSame(1, $storage->requeueFailed([self::ID_2]));
        $requeued = $storage->fetchUnpublished(10);
        self::assertSame([self::ID_2], self::ids($requeued));
        self::assertSame(0, $requeued[0]->attempts);
        self::assertNull($requeued[0]->lastError);

        self::assertSame(1, $storage->requeueFailed());
        // Transports take turns, the one whose next row has waited longest first.
        self::assertSame([self::ID_1, self::ID_2], self::ids($storage->fetchUnpublished(10)));
        self::assertSame([], $storage->fetchFailed(10));
    }

    public function test_requeueing_with_signatures_signs_and_counts_only_the_given_up_rows(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $due = '00000000-0000-7000-8000-000000000003';
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00', 'async'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:01:00', 'async'));
        $storage->store(self::message($due, '2026-01-01 10:02:00', 'async'));
        OutboxRows::fail($storage, self::ID_1, 3, 'not signed', null);
        OutboxRows::fail($storage, self::ID_2, 3, 'not signed', null);
        $signer = new OutboxSigner('secret');
        /** @var \ArrayObject<int, string> $signed */
        $signed = new \ArrayObject();
        $sign = static function (OutboxMessage $message) use ($signer, $signed): string {
            $signed[] = $message->id;

            return $signer->sign($message);
        };

        // A row that is still due and an unknown id are neither signed nor counted.
        self::assertSame(1, $storage->requeueFailed([strtoupper(self::ID_2), $due, self::UNKNOWN_ID], 'other', $sign));
        self::assertSame([self::ID_2], $signed->getArrayCopy());
        self::assertSame(1, $storage->requeueFailed([], null, $sign), 'Without ids, every row that is still given up.');
        self::assertSame([self::ID_2, self::ID_1], $signed->getArrayCopy());
        self::assertSame(0, $storage->requeueFailed([], null, $sign));
        self::assertSame([self::ID_2, self::ID_1], $signed->getArrayCopy());
        self::assertSame([], $storage->fetchFailed(10));

        $messages = [];
        foreach ($storage->fetchUnpublished(10) as $message) {
            $messages[$message->id] = $message;
        }
        self::assertSame([self::ID_1, self::ID_2, $due], array_keys($messages));
        self::assertTrue($signer->verify($messages[self::ID_1]));
        self::assertTrue($signer->verify($messages[self::ID_2]));
        self::assertNull($messages[$due]->signature, 'A row that was not requeued keeps its signature.');
        self::assertSame(['async', 'other', 'async'], array_map(static fn (OutboxMessage $message): ?string => $message->transportName, array_values($messages)));
        self::assertSame([0, 0, 0], array_map(static fn (OutboxMessage $message): int => $message->attempts, array_values($messages)));
        self::assertSame([null, null, null], array_map(static fn (OutboxMessage $message): ?string => $message->lastError, array_values($messages)));
    }

    public function test_a_claim_counts_the_attempt_and_postpones_the_message(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00', 'async'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:01:00'));
        $fetched = $storage->fetchUnpublished(10);

        self::assertSame([self::ID_1, self::ID_2], $storage->claim($fetched, [0 => new DateTimeImmutable('+1 minute')], 'relay-a'));

        self::assertSame([], $storage->fetchUnpublished(10), 'A claimed message is due again only after the retry time of its attempt.');
        $row = $this->connection->fetchAssociative('SELECT attempts, claim_token, claimed_at, available_at FROM somework_cqrs_outbox WHERE id = ?', [self::ID_1]);
        self::assertIsArray($row);
        self::assertSame([1, 'relay-a'], [(int) $row['attempts'], $row['claim_token']]);
        self::assertNotNull($row['claimed_at']);
        self::assertNotNull($row['available_at']);
    }

    public function test_only_one_relay_claims_an_attempt(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:01:00'));
        $fetchedByA = $storage->fetchUnpublished(10);
        $fetchedByB = $storage->fetchUnpublished(10);

        self::assertSame([self::ID_2], $storage->claim([$fetchedByB[1]], [0 => new DateTimeImmutable('-1 second')], 'relay-b'));
        self::assertSame([self::ID_1], $storage->claim($fetchedByA, [0 => new DateTimeImmutable('+1 minute')], 'relay-a'), 'Relay A read the second message before relay B claimed it.');
        self::assertSame([], $storage->claim($fetchedByB, [0 => new DateTimeImmutable('+1 minute')], 'relay-c'));
    }

    public function test_a_claim_needs_the_fetched_transport(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00', 'async'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:01:00'));

        // e.g. "outbox:failed --requeue --transport" moved the messages since they were fetched.
        self::assertSame([], $storage->claim([self::message(self::ID_1, '2026-01-01 10:00:00', 'other'), self::message(self::ID_2, '2026-01-01 10:01:00', 'async')], [0 => new DateTimeImmutable()], 'relay-a'));
        self::assertSame([self::ID_2, self::ID_1], $storage->claim([self::message(self::ID_2, '2026-01-01 10:01:00'), self::message(self::ID_1, '2026-01-01 10:00:00', 'async')], [0 => new DateTimeImmutable()], 'relay-a'));
    }

    public function test_rows_without_a_transport_name_are_claimed_only_while_they_still_have_none(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:01:00'));
        $fetched = $storage->fetchUnpublished(10);
        self::assertSame([null, null], array_map(static fn (OutboxMessage $message): ?string => $message->transportName, $fetched));
        // Meanwhile the second message was given up and requeued to a transport, with its attempts reset.
        OutboxRows::fail($storage, self::ID_2, 1, 'boom', null);
        self::assertSame(1, $storage->requeueFailed([self::ID_2], 'async'));

        self::assertSame([self::ID_1], $storage->claim($fetched, [0 => new DateTimeImmutable('+1 minute')], 'relay-a'));

        $row = $this->connection->fetchAssociative('SELECT attempts, claim_token, transport_name FROM somework_cqrs_outbox WHERE id = ?', [self::ID_1]);
        self::assertIsArray($row);
        self::assertSame([1, 'relay-a', null], [(int) $row['attempts'], $row['claim_token'], $row['transport_name']]);
        $due = $storage->fetchUnpublished(10);
        self::assertSame([self::ID_2], self::ids($due), 'The requeued message is due on its new transport, unclaimed.');
        self::assertSame(['async', 0, null], [$due[0]->transportName, $due[0]->attempts, $due[0]->claimedAt]);
    }

    public function test_messages_with_different_attempts_are_claimed_with_their_own_retry_time(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:01:00'));
        OutboxRows::fail($storage, self::ID_2, 2, 'boom', new DateTimeImmutable('-1 minute'));

        $claimed = $storage->claim($storage->fetchUnpublished(10), [0 => new DateTimeImmutable('2030-01-01 10:01:00+00:00'), 2 => new DateTimeImmutable('2030-01-01 10:04:00+00:00')], 'relay-a');

        self::assertSame([self::ID_1, self::ID_2], $claimed);
        self::assertSame(
            [[self::ID_1, 1, '2030-01-01 10:01:00'], [self::ID_2, 3, '2030-01-01 10:04:00']],
            array_map(static fn (array $row): array => [strtolower((string) $row['id']), (int) $row['attempts'], substr((string) $row['available_at'], 0, 19)], $this->connection->fetchAllAssociative('SELECT id, attempts, available_at FROM somework_cqrs_outbox ORDER BY created_at')),
        );
    }

    public function test_an_interrupted_attempt_is_fetched_with_its_claim_time(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        OutboxRows::claim($storage, self::ID_1, new DateTimeImmutable('-1 second'));

        $messages = $storage->fetchUnpublished(10);

        self::assertSame([self::ID_1], self::ids($messages));
        self::assertNotNull($messages[0]->claimedAt);
        self::assertSame(1, $messages[0]->attempts);
        self::assertSame([self::ID_1], $storage->claim($messages, [1 => new DateTimeImmutable('+1 minute')], 'next-relay'), 'The next relay claims it again.');
    }

    public function test_release_undoes_the_claim_of_an_unattempted_message(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:01:00'));
        OutboxRows::fail($storage, self::ID_2, 2, 'boom', new DateTimeImmutable('2026-01-01 11:00:00+00:00'));
        $fetched = $storage->fetchUnpublished(10);
        $storage->claim($fetched, [0 => new DateTimeImmutable('+1 hour'), 2 => new DateTimeImmutable('+1 hour')], 'relay-a');

        $storage->release($fetched, 'relay-b');
        self::assertSame([], $storage->fetchUnpublished(10), 'Claims of another relay stay.');

        $storage->release($fetched, 'relay-a');
        $messages = $storage->fetchUnpublished(10);
        self::assertSame([self::ID_1, self::ID_2], self::ids($messages));
        self::assertSame([0, 2], array_map(static fn (OutboxMessage $message): int => $message->attempts, $messages));
        self::assertSame([null, '2026-01-01T11:00:00+00:00'], array_map(static fn (OutboxMessage $message): ?string => $message->availableAt?->format(DATE_ATOM), $messages));
        self::assertSame([null, null], array_map(static fn (OutboxMessage $message): ?DateTimeImmutable => $message->claimedAt, $messages));
    }

    public function test_renew_extends_only_the_claims_of_the_token(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:01:00'));
        $fetched = $storage->fetchUnpublished(10);
        $storage->claim($fetched, [0 => new DateTimeImmutable('-1 second')], 'relay-a');
        // The claim of the second message ran out and another relay took it over.
        $storage->claim([$storage->fetchUnpublished(10)[1]], [1 => new DateTimeImmutable('+1 minute')], 'relay-b');

        self::assertSame([self::ID_1], $storage->renew($fetched, [0 => new DateTimeImmutable('+1 hour')], 'relay-a'));
        self::assertSame([self::ID_1], $storage->renew($fetched, [0 => new DateTimeImmutable('+1 hour')], 'relay-a'), 'Renewing twice within a second still reports the claim.');
        self::assertSame([], $storage->fetchUnpublished(10), 'The renewed claim holds until its new retry time.');
    }

    public function test_a_failure_is_only_recorded_with_the_claim_token(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $token = OutboxRows::claim($storage, self::ID_1, new DateTimeImmutable('+1 minute'));

        self::assertFalse($storage->recordFailure(self::ID_1, 'another-relay', 1, 'boom', null));
        self::assertTrue($storage->recordFailure(self::ID_1, $token, 1, 'boom', new DateTimeImmutable('-1 second')));
        self::assertFalse($storage->recordFailure(self::ID_1, $token, 1, 'boom', new DateTimeImmutable('-1 second')), 'Recording the failure ended the claim.');

        $messages = $storage->fetchUnpublished(10);
        self::assertSame(1, $messages[0]->attempts);
        self::assertSame('boom', $messages[0]->lastError);
        self::assertNull($messages[0]->claimedAt);
        self::assertFalse($storage->recordFailure(self::UNKNOWN_ID, $token, 1, 'boom', null));
    }

    public function test_a_published_message_records_no_failure(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $token = OutboxRows::claim($storage, self::ID_1, new DateTimeImmutable('+1 minute'));
        $storage->markPublished([self::ID_1]);

        self::assertFalse($storage->recordFailure(self::ID_1, $token, 1, 'late failure', null));
        self::assertSame([], $storage->fetchFailed(10));
    }

    public function test_a_given_up_message_cannot_be_claimed(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $fetched = $storage->fetchUnpublished(10);
        OutboxRows::fail($storage, self::ID_1, 0, 'boom', null);

        self::assertSame([], $storage->claim($fetched, [0 => new DateTimeImmutable()], 'relay-a'));
        self::assertSame(0, $storage->fetchFailed(10)[0]->attempts);
    }

    public function test_setup_adds_the_failure_columns_to_a_table_of_an_earlier_version(): void
    {
        TestDatabase::createTableOfVersion04($this->connection);
        $this->connection->insert('somework_cqrs_outbox', ['id' => self::ID_1, 'body' => 'body', 'headers' => '{}', 'created_at' => '2026-01-01 10:00:00']);

        (new DbalOutboxStorage($this->connection, autoSetup: false))->setup();

        self::assertSame(
            [['attempts' => 0, 'available_at' => null, 'failed_at' => null, 'last_error' => null]],
            $this->connection->fetchAllAssociative('SELECT attempts, available_at, failed_at, last_error FROM somework_cqrs_outbox'),
        );
        $messages = (new DbalOutboxStorage($this->connection, autoSetup: false))->fetchUnpublished(10);
        self::assertSame([self::ID_1], self::ids($messages));
        self::assertSame(0, $messages[0]->attempts);
        self::assertTrue(TestDatabase::hasIndex($this->connection, 'somework_cqrs_outbox', 'idx_somework_cqrs_outbox_pending'));
        self::assertFalse(TestDatabase::hasIndex($this->connection, 'somework_cqrs_outbox', 'idx_somework_cqrs_outbox_published_created'), 'The index of 0.4 is replaced.');
    }

    public function test_auto_setup_upgrades_a_table_of_an_earlier_version_on_first_use(): void
    {
        TestDatabase::createTableOfVersion04($this->connection);
        $storage = new DbalOutboxStorage($this->connection);

        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        OutboxRows::fail($storage, self::ID_1, 1, 'boom', null);

        self::assertSame(1, $storage->fetchFailed(10)[0]->attempts);
    }

    public function test_a_table_of_an_earlier_version_asks_for_an_upgrade_without_auto_setup(): void
    {
        TestDatabase::createTableOfVersion04($this->connection);
        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The outbox table "somework_cqrs_outbox" lacks columns this version of the bundle needs (attempts, available_at, failed_at, last_error, claim_token, claimed_at, signature). Upgrade it with "bin/console somework:cqrs:outbox:setup"');

        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
    }

    public function test_the_upgrade_never_runs_inside_a_transaction(): void
    {
        TestDatabase::createTableOfVersion04($this->connection);
        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $this->connection->beginTransaction();

        try {
            $storage->setup();
            self::fail('Expected the upgrade to be refused.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('lacks the columns attempts, available_at, failed_at, last_error, claim_token, claimed_at, signature and cannot be changed inside an open database transaction', $exception->getMessage());
        } finally {
            $this->connection->rollBack();
        }
    }

    public function test_a_reserved_word_as_table_name_is_reported(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The database rejected a query on the outbox table "order"; the name is probably a reserved word of this database.');

        (new DbalOutboxStorage($this->connection, 'order'))->setup();
    }

    public function test_dates_in_a_daylight_saving_gap_of_the_default_time_zone_are_read_back_unchanged(): void
    {
        $defaultTimeZone = date_default_timezone_get();
        $storage = new DbalOutboxStorage($this->connection);
        // 02:30 does not exist in Berlin on 2026-03-29 (clocks jump from 02:00 to 03:00).
        $storage->store(new OutboxMessage(self::ID_1, 'body', '{}', new DateTimeImmutable('2026-03-29 02:30:00+00:00')));

        try {
            date_default_timezone_set('Europe/Berlin');
            $messages = $storage->fetchUnpublished(10);
        } finally {
            date_default_timezone_set($defaultTimeZone);
        }

        self::assertSame('2026-03-29T02:30:00+00:00', $messages[0]->createdAt->format(DATE_ATOM));
    }

    public function test_mark_published_hides_the_message_and_is_idempotent(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message('00000000-0000-7000-8000-000000000001', '2026-01-01 10:00:00'));

        $storage->markPublished(['00000000-0000-7000-8000-000000000001']);
        // A publication time far in the past shows whether the second call stamps the row again.
        $this->connection->executeStatement("UPDATE somework_cqrs_outbox SET published_at = '2000-01-01 00:00:00'");
        $storage->markPublished(['00000000-0000-7000-8000-000000000001']);

        self::assertSame([], $storage->fetchUnpublished(10));
        self::assertStringStartsWith('2000-01-01 00:00:00', (string) $this->connection->fetchOne('SELECT published_at FROM somework_cqrs_outbox'), 'The first publication time stays (the purge relies on it).');
    }

    public function test_mark_published_ignores_unknown_ids_and_clears_the_claim(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        OutboxRows::claim($storage, self::ID_1, new DateTimeImmutable('+1 minute'));

        $storage->markPublished([self::UNKNOWN_ID, strtoupper(self::ID_1)]);

        self::assertSame([['claim_token' => null, 'claimed_at' => null]], $this->connection->fetchAllAssociative('SELECT claim_token, claimed_at FROM somework_cqrs_outbox WHERE published_at IS NOT NULL'));
    }

    public function test_publishing_clears_the_failure_markers(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        // What the relay records before its last attempt.
        OutboxRows::fail($storage, self::ID_1, 10, 'interrupted', null);

        $storage->markPublished([self::ID_1]);

        self::assertSame([['failed_at' => null, 'last_error' => null]], $this->connection->fetchAllAssociative('SELECT failed_at, last_error FROM somework_cqrs_outbox'));
    }

    public function test_status_counts_due_and_given_up_messages(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $storage->store(self::message(self::ID_2, '2026-01-01 11:00:00'));
        // Stored long ago, but postponed until a moment ago: it waits since its retry time.
        OutboxRows::fail($storage, self::ID_1, 1, 'boom', new DateTimeImmutable('2026-01-01 12:00:00+00:00'));

        $status = $storage->status();

        self::assertSame(2, $status->due);
        self::assertSame('2026-01-01T11:00:00+00:00', $status->oldestDue?->format(DATE_ATOM));
        self::assertSame(1, $status->retrying);
        self::assertSame('2026-01-01T10:00:00+00:00', $status->oldestRetrying?->format(DATE_ATOM));
        self::assertSame(0, $status->failed);
    }

    public function test_status_counts_messages_that_keep_failing_even_while_they_are_postponed(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $storage->store(self::message(self::ID_2, '2026-01-01 11:00:00'));
        OutboxRows::fail($storage, self::ID_1, 3, 'TransportException: Connection refused', new DateTimeImmutable('+1 hour'));
        OutboxRows::fail($storage, self::ID_2, 10, 'RuntimeException: boom', null);

        $status = $storage->status();

        self::assertSame(0, $status->due);
        self::assertNull($status->oldestDue);
        self::assertSame(1, $status->retrying);
        self::assertSame('2026-01-01T10:00:00+00:00', $status->oldestRetrying?->format(DATE_ATOM));
        self::assertSame(1, $status->failed);
    }

    public function test_reads_go_to_the_primary_of_a_primary_read_replica_connection(): void
    {
        // A lagging replica would show the relay rows already published, or none that are due.
        $file = tempnam(sys_get_temp_dir(), 'cqrs-outbox');
        $params = ['driver' => 'pdo_sqlite', 'path' => $file];
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'wrapperClass' => PrimaryReadReplicaConnection::class, 'primary' => $params, 'replica' => [$params]]);

        try {
            $storage = new DbalOutboxStorage($connection, autoSetup: false);
            (new DbalOutboxStorage(DriverManager::getConnection($params)))->setup();

            $storage->fetchUnpublished(10);

            self::assertTrue($connection->isConnectedToPrimary());
        } finally {
            $connection->close();
            unlink($file);
        }
    }

    public function test_the_table_gets_the_default_table_options_of_the_connection(): void
    {
        // e.g. a database whose default charset is latin1, used through a utf8mb4 connection.
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Table options (charset, collation) are specific to MySQL and MariaDB.');
        }

        $connection = DriverManager::getConnection([...$this->connection->getParams(), 'defaultTableOptions' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin']]);
        (new DbalOutboxStorage($connection))->setup();

        self::assertSame('utf8mb4_bin', $connection->fetchOne('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['somework_cqrs_outbox']));
    }

    public function test_marking_as_published_is_retried_after_serialization_failures(): void
    {
        // e.g. overlapping relays at SERIALIZABLE isolation: a failure would send the messages again.
        $failures = new BeforeQueryMiddleware('published_at = ');
        $connection = TestDatabase::connect(null, [$failures]);
        $storage = new DbalOutboxStorage($connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:00:01'));

        $remaining = 3;
        $fail = static function () use (&$remaining, $failures, &$fail): void {
            if ($remaining-- > 0) {
                $failures->callback = $fail;

                // Retryable on every platform: SQLite "database is locked", PostgreSQL 40001, MySQL 1213.
                throw new class('database is locked', '40001', 1213) extends AbstractException {};
            }
        };
        $failures->callback = $fail;
        $storage->markPublished([self::ID_1]);
        self::assertSame([self::ID_2], self::ids($storage->fetchUnpublished(10)));

        $remaining = 6;
        $failures->callback = $fail;
        $this->expectException(RetryableException::class);
        $storage->markPublished([self::ID_2]);
    }

    public function test_status_reports_unfinished_claims(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $storage->store(self::message(self::ID_2, '2026-01-01 11:00:00'));
        $storage->store(self::message(self::UNKNOWN_ID, '2026-01-01 12:00:00'));
        // One attempt runs, one was interrupted (its relay died) and is due again.
        OutboxRows::claim($storage, self::ID_1, new DateTimeImmutable('+1 minute'));
        OutboxRows::claim($storage, self::ID_2, new DateTimeImmutable('-1 minute'));

        $status = $storage->status();

        self::assertSame(1, $status->inFlight);
        self::assertNotNull($status->claimExpiredSince);
        self::assertEqualsWithDelta(time() - 60, $status->claimExpiredSince->getTimestamp(), 5, 'The claim ran out at the retry time of its attempt.');
        self::assertSame(2, $status->due, 'The interrupted attempt is due, and the new message.');
        self::assertSame(0, $status->retrying, 'No attempt failed.');
        self::assertFalse($status->capped);
    }

    public function test_status_counts_stop_at_the_cap(): void
    {
        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $storage->setup();
        $this->connection->beginTransaction();
        for ($i = 0; $i <= OutboxStatus::COUNT_CAP; ++$i) {
            $this->connection->insert('somework_cqrs_outbox', ['id' => sprintf('00000000-0000-7000-8000-%012d', $i), 'body' => 'b', 'headers' => '{}', 'created_at' => '2026-01-01 10:00:00']);
        }
        $this->connection->commit();

        $status = $storage->status();

        self::assertSame(OutboxStatus::COUNT_CAP, $status->due);
        self::assertTrue($status->capped);
        self::assertSame('2026-01-01T10:00:00+00:00', $status->oldestDue?->format(DATE_ATOM));
    }

    public function test_purge_deletes_only_old_published_messages(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message('00000000-0000-7000-8000-000000000001', '2026-01-01 10:00:00'));
        $storage->store(self::message('00000000-0000-7000-8000-000000000002', '2026-01-01 10:00:00'));
        $storage->markPublished(['00000000-0000-7000-8000-000000000001']);

        self::assertSame(0, $storage->purgePublished(new DateTimeImmutable('-1 hour')));
        self::assertSame(1, $storage->purgePublished(new DateTimeImmutable('+1 hour')));
        self::assertCount(1, $storage->fetchUnpublished(10));
    }

    public function test_purge_deletes_more_published_messages_than_fit_in_one_batch(): void
    {
        $queries = new QueryLog();
        $this->connection = TestDatabase::connect($queries);
        $storage = new DbalOutboxStorage($this->connection);
        $storage->setup();
        // One more than the rows a purge deletes per statement.
        $published = array_map(static fn (int $i): string => sprintf('00000000-0000-7000-8000-%012d', $i), range(1, 1001));
        $this->connection->transactional(static function () use ($storage, $published): void {
            foreach ($published as $id) {
                $storage->store(self::message($id, '2026-01-01 10:00:00'));
            }
        });
        $unpublished = '00000000-0000-7000-8000-100000000000';
        $storage->store(self::message($unpublished, '2026-01-01 10:00:00'));
        $storage->markPublished($published);
        $queries->flush();

        self::assertSame(1001, $storage->purgePublished(new DateTimeImmutable('+1 hour')));

        $deletes = array_values(array_filter($queries->flush(), static fn (string $sql): bool => str_starts_with($sql, 'DELETE')));
        self::assertCount(2, $deletes, 'The rows are deleted in batches.');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM somework_cqrs_outbox'));
        self::assertSame([$unpublished], self::ids($storage->fetchUnpublished(10)));
        self::assertSame(0, $storage->purgePublished(new DateTimeImmutable('+1 hour')));
    }

    public function test_auto_setup_creates_the_table_outside_of_a_transaction(): void
    {
        (new DbalOutboxStorage($this->connection))->fetchUnpublished(1);

        self::assertTrue($this->connection->createSchemaManager()->tablesExist(['somework_cqrs_outbox']));
    }

    public function test_auto_setup_never_runs_ddl_inside_a_transaction(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $this->connection->beginTransaction();

        try {
            $storage->store(self::message('00000000-0000-7000-8000-000000000001', '2026-01-01 10:00:00'));
            self::fail('Expected the missing table to be reported.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('The outbox table "somework_cqrs_outbox" does not exist', $exception->getMessage());
            self::assertStringContainsString('somework:cqrs:outbox:setup', $exception->getMessage());
        } finally {
            $this->connection->rollBack();
        }

        self::assertFalse($this->connection->createSchemaManager()->tablesExist(['somework_cqrs_outbox']));
    }

    public function test_store_inside_a_transaction_works_once_the_table_exists(): void
    {
        (new DbalOutboxStorage($this->connection))->setup();
        $storage = new DbalOutboxStorage($this->connection);

        $this->connection->beginTransaction();
        $storage->store(self::message('00000000-0000-7000-8000-000000000001', '2026-01-01 10:00:00'));
        $this->connection->rollBack();

        self::assertSame([], $storage->fetchUnpublished(10), 'The insert takes part in the caller transaction.');
    }

    public function test_store_inside_a_transaction_does_not_depend_on_the_schema_asset_filter(): void
    {
        (new DbalOutboxStorage($this->connection))->setup();
        // e.g. a Doctrine "schema_filter" that hides the table from the schema manager.
        $this->connection->getConfiguration()->setSchemaAssetsFilter(static fn (string $name): bool => false);
        $storage = new DbalOutboxStorage($this->connection);

        $this->connection->beginTransaction();
        $storage->store(self::message('00000000-0000-7000-8000-000000000001', '2026-01-01 10:00:00'));
        $this->connection->commit();

        self::assertCount(1, $storage->fetchUnpublished(10));
    }

    public function test_dates_are_stored_in_utc_whatever_the_default_time_zone(): void
    {
        $defaultTimeZone = date_default_timezone_get();
        $storage = new DbalOutboxStorage($this->connection);

        try {
            // Written by a process running in Berlin during daylight saving time ...
            date_default_timezone_set('Europe/Berlin');
            $storage->store(new OutboxMessage('b0000000-0000-7000-8000-000000000002', 'body', '{}', new DateTimeImmutable('2026-10-25 02:30:00+02:00')));
            // ... and by one running in UTC, 10 minutes later.
            date_default_timezone_set('UTC');
            $storage->store(new OutboxMessage('a0000000-0000-7000-8000-000000000001', 'body', '{}', new DateTimeImmutable('2026-10-25 00:40:00+00:00')));

            date_default_timezone_set('America/New_York');
            $messages = $storage->fetchUnpublished(10);
        } finally {
            date_default_timezone_set($defaultTimeZone);
        }

        self::assertSame(['b0000000-0000-7000-8000-000000000002', 'a0000000-0000-7000-8000-000000000001'], array_map(static fn (OutboxMessage $message): string => $message->id, $messages));
        self::assertSame('2026-10-25T00:30:00+00:00', $messages[0]->createdAt->format(DATE_ATOM));
    }

    public function test_setup_is_idempotent(): void
    {
        $storage = new DbalOutboxStorage($this->connection);

        $storage->setup();
        (new DbalOutboxStorage($this->connection))->setup();

        self::assertTrue($this->connection->createSchemaManager()->tablesExist(['somework_cqrs_outbox']));
    }

    public function test_without_auto_setup_a_missing_table_is_reported(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The outbox table "somework_cqrs_outbox" does not exist.');

        (new DbalOutboxStorage($this->connection, autoSetup: false))->fetchUnpublished(1);
    }

    public function test_custom_table_name(): void
    {
        $storage = new DbalOutboxStorage($this->connection, 'app_outbox');
        $storage->store(self::message('00000000-0000-7000-8000-000000000001', '2026-01-01 10:00:00'));

        self::assertTrue($this->connection->createSchemaManager()->tablesExist(['app_outbox']));
        self::assertCount(1, $storage->fetchUnpublished(10));
    }

    public function test_schema_definition(): void
    {
        $schema = new Schema();
        $table = DbalOutboxStorage::addTableToSchema($schema);
        $sql = implode(";\n", (new SQLitePlatform())->getCreateTableSQL($table));

        self::assertSame($schema->getTable('somework_cqrs_outbox'), $table);
        self::assertMatchesRegularExpression('/PRIMARY KEY\s*\(\s*id\s*\)/i', $sql);
        self::assertStringNotContainsString('published_created', $sql);
        self::assertStringContainsString('CREATE INDEX idx_somework_cqrs_outbox_pending ON somework_cqrs_outbox (published_at, failed_at, transport_name, available_at, created_at, id)', $sql);
        self::assertFalse($table->getColumn('transport_name')->getNotnull());
        self::assertFalse($table->getColumn('published_at')->getNotnull());
        self::assertSame(190, $table->getColumn('transport_name')->getLength());
        self::assertTrue($table->getColumn('attempts')->getNotnull());
        self::assertSame(0, (int) $table->getColumn('attempts')->getDefault());
        foreach (['available_at', 'failed_at', 'last_error'] as $column) {
            self::assertFalse($table->getColumn($column)->getNotnull(), $column);
        }
    }

    public function test_long_table_names_get_a_short_index_name(): void
    {
        $tableName = 'outbox_'.str_repeat('x', 60);
        $sql = implode(";\n", (new SQLitePlatform())->getCreateTableSQL(DbalOutboxStorage::addTableToSchema(new Schema(), $tableName)));

        self::assertMatchesRegularExpression('/CREATE INDEX (idx_[0-9a-f]{16}_pending) ON/', $sql);
    }

    /**
     * @param list<OutboxMessage> $messages
     *
     * @return list<string>
     */
    private static function ids(array $messages): array
    {
        return array_map(static fn (OutboxMessage $message): string => $message->id, $messages);
    }

    private function assertQualifiedTableNameWorks(string $tableName): void
    {
        (new DbalOutboxStorage($this->connection, $tableName))->setup();
        // Every process has its own storage (the table must be found again, not created twice).
        (new DbalOutboxStorage($this->connection, $tableName))->setup();
        $storage = new DbalOutboxStorage($this->connection, $tableName);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));

        self::assertSame([self::ID_1], self::ids((new DbalOutboxStorage($this->connection, $tableName))->fetchUnpublished(10)));
        self::assertSame([], (new DbalOutboxStorage($this->connection, $tableName))->pendingChanges());
        self::assertNull((new DbalOutboxStorage($this->connection, $tableName))->status()->oldestRetrying);
    }

    private static function message(string $id, string $createdAt, ?string $transportName = null): OutboxMessage
    {
        return new OutboxMessage($id, 'body', '{"type":"test"}', new DateTimeImmutable($createdAt), $transportName);
    }
}
