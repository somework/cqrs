<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;

use function array_map;
use function date_default_timezone_get;
use function date_default_timezone_set;
use function implode;
use function str_repeat;
use function time;

use const DATE_ATOM;

/**
 * Runs the storage against a real (in-memory SQLite) database.
 */
#[CoversClass(DbalOutboxStorage::class)]
final class DbalOutboxStorageTest extends TestCase
{
    private const ID_1 = '00000000-0000-7000-8000-000000000001';

    private const ID_2 = '00000000-0000-7000-8000-000000000002';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function test_stores_and_fetches_unpublished_messages_oldest_first(): void
    {
        $storage = new DbalOutboxStorage($this->connection);

        $storage->store(self::message('b0000000-0000-7000-8000-000000000002', '2026-01-01 10:00:00', 'async'));
        $storage->store(self::message('a0000000-0000-7000-8000-000000000001', '2026-01-01 10:00:00'));
        $storage->store(self::message('c0000000-0000-7000-8000-000000000003', '2026-01-01 09:00:00'));

        $messages = $storage->fetchUnpublished(10);

        self::assertSame(
            ['c0000000-0000-7000-8000-000000000003', 'a0000000-0000-7000-8000-000000000001', 'b0000000-0000-7000-8000-000000000002'],
            array_map(static fn (OutboxMessage $message): string => $message->id, $messages),
        );
        self::assertSame('async', $messages[2]->transportName);
        self::assertNull($messages[1]->transportName);
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

    public function test_a_failed_message_waits_for_its_retry_time(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:01:00'));

        $storage->markFailed(self::ID_1, 1, 'RuntimeException: boom', new DateTimeImmutable('+1 hour'));

        self::assertSame([self::ID_2], self::ids($storage->fetchUnpublished(10)), 'The failed message is skipped until its retry time.');

        $storage->markFailed(self::ID_2, 1, 'RuntimeException: boom', new DateTimeImmutable('-1 second'));
        $messages = $storage->fetchUnpublished(10);

        self::assertSame([self::ID_2], self::ids($messages));
        self::assertSame(1, $messages[0]->attempts);
    }

    public function test_the_number_of_attempts_is_stored_as_given(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));

        // The relay records an attempt before sending it, and again with the error when it fails.
        $storage->markFailed(self::ID_1, 1, 'interrupted', new DateTimeImmutable('-1 minute'));
        $storage->markFailed(self::ID_1, 1, 'first', new DateTimeImmutable('-1 minute'));
        $storage->markFailed(self::ID_1, 2, 'second', new DateTimeImmutable('-1 minute'));

        self::assertSame(2, $storage->fetchUnpublished(1)[0]->attempts);
    }

    public function test_a_given_up_message_is_listed_and_can_be_requeued(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00', 'async'));
        $storage->store(self::message(self::ID_2, '2026-01-01 10:01:00'));
        $storage->markFailed(self::ID_1, 1, 'first failure', new DateTimeImmutable('-1 minute'));
        $storage->markFailed(self::ID_1, 2, 'MessageDecodingFailedException: gone', null);
        $storage->markFailed(self::ID_2, 1, 'RuntimeException: boom', null);

        self::assertSame([], self::ids($storage->fetchUnpublished(10)), 'Given-up messages are no longer due.');

        $failed = $storage->fetchFailed(10);
        self::assertSame([self::ID_1, self::ID_2], array_map(static fn (array $message): string => $message['id'], $failed));
        self::assertSame(2, $failed[0]['attempts']);
        self::assertSame('MessageDecodingFailedException: gone', $failed[0]['last_error']);
        self::assertSame('async', $failed[0]['transport_name']);
        self::assertSame('2026-01-01T10:00:00+00:00', $failed[0]['created_at']->format(DATE_ATOM));
        self::assertEqualsWithDelta(time(), $failed[0]['failed_at']->getTimestamp(), 5);

        self::assertSame(1, $storage->requeueFailed([self::ID_2]));
        $requeued = $storage->fetchUnpublished(10);
        self::assertSame([self::ID_2], self::ids($requeued));
        self::assertSame(0, $requeued[0]->attempts);

        self::assertSame(1, $storage->requeueFailed());
        self::assertSame([self::ID_1, self::ID_2], self::ids($storage->fetchUnpublished(10)));
        self::assertSame([], $storage->fetchFailed(10));
    }

    public function test_mark_failed_rejects_unknown_ids_and_ignores_published_messages(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $storage->markPublished(self::ID_1);

        $storage->markFailed(self::ID_1, 1, 'late failure', null);
        self::assertSame([], $storage->fetchFailed(10));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Outbox message "missing" not found in table "somework_cqrs_outbox" — cannot record an attempt.');

        $storage->markFailed('missing', 1, 'error', null);
    }

    public function test_setup_adds_the_failure_columns_to_a_table_of_an_earlier_version(): void
    {
        $this->createTableOfVersion04();
        $this->connection->insert('somework_cqrs_outbox', ['id' => self::ID_1, 'body' => 'body', 'headers' => '{}', 'created_at' => '2026-01-01 10:00:00']);

        (new DbalOutboxStorage($this->connection, autoSetup: false))->setup();

        self::assertSame(
            [['attempts' => 0, 'available_at' => null, 'failed_at' => null, 'last_error' => null]],
            $this->connection->fetchAllAssociative('SELECT attempts, available_at, failed_at, last_error FROM somework_cqrs_outbox'),
        );
        $messages = (new DbalOutboxStorage($this->connection, autoSetup: false))->fetchUnpublished(10);
        self::assertSame([self::ID_1], self::ids($messages));
        self::assertSame(0, $messages[0]->attempts);
    }

    public function test_auto_setup_upgrades_a_table_of_an_earlier_version_on_first_use(): void
    {
        $this->createTableOfVersion04();
        $storage = new DbalOutboxStorage($this->connection);

        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));
        $storage->markFailed(self::ID_1, 1, 'boom', null);

        self::assertSame(1, $storage->fetchFailed(10)[0]['attempts']);
    }

    public function test_a_table_of_an_earlier_version_accepts_messages_but_asks_for_an_upgrade(): void
    {
        $this->createTableOfVersion04();
        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);

        // Storing works before the upgrade, so deploying the new version does not break writes.
        $storage->store(self::message(self::ID_1, '2026-01-01 10:00:00'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The outbox table "somework_cqrs_outbox" lacks columns this version of the bundle needs (attempts, available_at, failed_at, last_error). Upgrade it with "bin/console somework:cqrs:outbox:setup"');

        $storage->fetchUnpublished(10);
    }

    public function test_the_upgrade_never_runs_inside_a_transaction(): void
    {
        $this->createTableOfVersion04();
        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $this->connection->beginTransaction();

        try {
            $storage->setup();
            self::fail('Expected the upgrade to be refused.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('lacks the columns attempts, available_at, failed_at, last_error and cannot be changed inside an open database transaction', $exception->getMessage());
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

        $storage->markPublished('00000000-0000-7000-8000-000000000001');
        $storage->markPublished('00000000-0000-7000-8000-000000000001');

        self::assertSame([], $storage->fetchUnpublished(10));
    }

    public function test_mark_published_rejects_unknown_ids(): void
    {
        $storage = new DbalOutboxStorage($this->connection);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Outbox message "missing" not found in table "somework_cqrs_outbox"');

        $storage->markPublished('missing');
    }

    public function test_purge_deletes_only_old_published_messages(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        $storage->store(self::message('00000000-0000-7000-8000-000000000001', '2026-01-01 10:00:00'));
        $storage->store(self::message('00000000-0000-7000-8000-000000000002', '2026-01-01 10:00:00'));
        $storage->markPublished('00000000-0000-7000-8000-000000000001');

        self::assertSame(0, $storage->purgePublished(new DateTimeImmutable('-1 hour')));
        self::assertSame(1, $storage->purgePublished(new DateTimeImmutable('+1 hour')));
        self::assertCount(1, $storage->fetchUnpublished(10));
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
        self::assertStringContainsString('CREATE INDEX idx_somework_cqrs_outbox_published_created ON somework_cqrs_outbox (published_at, created_at)', $sql);
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

        self::assertMatchesRegularExpression('/CREATE INDEX (idx_[0-9a-f]{16}_published_created) ON/', $sql);
    }

    /**
     * The table as created by 0.4.
     */
    private function createTableOfVersion04(): void
    {
        $this->connection->executeStatement('CREATE TABLE somework_cqrs_outbox (id CHAR(36) NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, transport_name VARCHAR(190) DEFAULT NULL, created_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, PRIMARY KEY(id))');
        $this->connection->executeStatement('CREATE INDEX idx_somework_cqrs_outbox_published_created ON somework_cqrs_outbox (published_at, created_at)');
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

    private static function message(string $id, string $createdAt, ?string $transportName = null): OutboxMessage
    {
        return new OutboxMessage($id, 'body', '{"type":"test"}', new DateTimeImmutable($createdAt), $transportName);
    }
}
