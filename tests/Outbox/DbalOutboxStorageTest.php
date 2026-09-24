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
use function implode;
use function str_repeat;

/**
 * Runs the storage against a real (in-memory SQLite) database.
 */
#[CoversClass(DbalOutboxStorage::class)]
final class DbalOutboxStorageTest extends TestCase
{
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

    public function test_limit_and_offset(): void
    {
        $storage = new DbalOutboxStorage($this->connection);
        foreach (['1', '2', '3'] as $minute) {
            $storage->store(self::message('00000000-0000-7000-8000-00000000000'.$minute, '2026-01-01 10:0'.$minute.':00'));
        }

        $page = $storage->fetchUnpublished(1, 1);

        self::assertCount(1, $page);
        self::assertSame('00000000-0000-7000-8000-000000000002', $page[0]->id);
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
            self::assertStringContainsString('cannot be created inside an open database transaction', $exception->getMessage());
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

    public function test_setup_is_idempotent(): void
    {
        $storage = new DbalOutboxStorage($this->connection);

        $storage->setup();
        (new DbalOutboxStorage($this->connection))->setup();

        self::assertTrue($this->connection->createSchemaManager()->tablesExist(['somework_cqrs_outbox']));
    }

    public function test_without_auto_setup_a_missing_table_is_a_database_error(): void
    {
        $this->expectException(\Doctrine\DBAL\Exception::class);

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
    }

    public function test_long_table_names_get_a_short_index_name(): void
    {
        $tableName = 'outbox_'.str_repeat('x', 60);
        $sql = implode(";\n", (new SQLitePlatform())->getCreateTableSQL(DbalOutboxStorage::addTableToSchema(new Schema(), $tableName)));

        self::assertMatchesRegularExpression('/CREATE INDEX (idx_[0-9a-f]{16}_published_created) ON/', $sql);
    }

    private static function message(string $id, string $createdAt, ?string $transportName = null): OutboxMessage
    {
        return new OutboxMessage($id, 'body', '{"type":"test"}', new DateTimeImmutable($createdAt), $transportName);
    }
}
