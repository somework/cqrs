<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;

#[CoversClass(DbalOutboxStorage::class)]
final class DbalOutboxStorageTest extends TestCase
{
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
    }

    public function test_implements_outbox_storage(): void
    {
        $storage = new DbalOutboxStorage($this->connection);

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(OutboxStorage::class, $storage);
    }

    public function test_store_inserts_row(): void
    {
        $createdAt = new DateTimeImmutable('2024-01-15 10:30:00');
        $message = new OutboxMessage('msg-1', '{"data":1}', '{"type":"Cmd"}', $createdAt, 'async');

        $this->connection->expects(self::once())
            ->method('insert')
            ->with(
                'somework_cqrs_outbox',
                [
                    'id' => 'msg-1',
                    'body' => '{"data":1}',
                    'headers' => '{"type":"Cmd"}',
                    'transport_name' => 'async',
                    'created_at' => $createdAt,
                    'published_at' => null,
                ],
                [
                    'created_at' => Types::DATETIME_IMMUTABLE,
                    'published_at' => Types::DATETIME_IMMUTABLE,
                ],
            );

        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $storage->store($message);
    }

    public function test_store_with_custom_table_name(): void
    {
        $message = new OutboxMessage('msg-2', '{}', '{}', new DateTimeImmutable());

        $this->connection->expects(self::once())
            ->method('insert')
            ->with('custom_outbox', self::anything(), self::anything());

        $storage = new DbalOutboxStorage($this->connection, tableName: 'custom_outbox', autoSetup: false);
        $storage->store($message);
    }

    public function test_fetch_unpublished_returns_messages(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')
            ->willReturn([
                [
                    'id' => 'msg-1',
                    'body' => '{"data":1}',
                    'headers' => '{"type":"Cmd"}',
                    'transport_name' => 'async',
                    'created_at' => '2024-01-15 10:30:00',
                ],
                [
                    'id' => 'msg-2',
                    'body' => '{"data":2}',
                    'headers' => '{"type":"Qry"}',
                    'transport_name' => null,
                    'created_at' => '2024-01-15 10:31:00',
                ],
            ]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connection->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $messages = $storage->fetchUnpublished(10);

        self::assertCount(2, $messages);
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(OutboxMessage::class, $messages[0]);
        self::assertSame('msg-1', $messages[0]->id);
        self::assertSame('{"data":1}', $messages[0]->body);
        self::assertSame('{"type":"Cmd"}', $messages[0]->headers);
        self::assertSame('async', $messages[0]->transportName);
        self::assertSame('msg-2', $messages[1]->id);
        self::assertNull($messages[1]->transportName);
    }

    public function test_fetch_unpublished_returns_empty_list(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connection->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $messages = $storage->fetchUnpublished(10);

        self::assertSame([], $messages);
    }

    public function test_mark_published_updates_row(): void
    {
        $this->connection->expects(self::once())
            ->method('update')
            ->with(
                'somework_cqrs_outbox',
                self::callback(static function (array $data): bool {
                    self::assertArrayHasKey('published_at', $data);
                    self::assertInstanceOf(DateTimeImmutable::class, $data['published_at']);

                    return true;
                }),
                ['id' => 'msg-1'],
                ['published_at' => Types::DATETIME_IMMUTABLE],
            )
            ->willReturn(1);

        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $storage->markPublished('msg-1');
    }

    public function test_setup_creates_table(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->expects(self::once())
            ->method('createTable')
            ->with(self::callback(static function (Table $table): bool {
                self::assertSame('somework_cqrs_outbox', $table->getObjectName()->toString());
                self::assertTrue($table->hasColumn('id'));
                self::assertTrue($table->hasColumn('body'));
                self::assertTrue($table->hasColumn('headers'));
                self::assertTrue($table->hasColumn('transport_name'));
                self::assertTrue($table->hasColumn('created_at'));
                self::assertTrue($table->hasColumn('published_at'));

                return true;
            }));

        $this->connection->method('createSchemaManager')
            ->willReturn($schemaManager);

        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $storage->setup();
    }

    public function test_setup_catches_table_exists_exception(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $driverException = $this->createMock(\Doctrine\DBAL\Driver\Exception::class);
        $schemaManager->method('createTable')
            ->willThrowException(new TableExistsException($driverException, null));

        $this->connection->method('createSchemaManager')
            ->willReturn($schemaManager);

        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);

        // Should not throw
        $storage->setup();
        $this->addToAssertionCount(1);
    }

    public function test_add_table_to_schema(): void
    {
        $schema = new Schema();

        DbalOutboxStorage::addTableToSchema($schema);

        self::assertTrue($schema->hasTable('somework_cqrs_outbox'));

        $table = $schema->getTable('somework_cqrs_outbox');
        self::assertTrue($table->hasColumn('id'));
        self::assertTrue($table->hasColumn('body'));
        self::assertTrue($table->hasColumn('headers'));
        self::assertTrue($table->hasColumn('transport_name'));
        self::assertTrue($table->hasColumn('created_at'));
        self::assertTrue($table->hasColumn('published_at'));
        self::assertTrue($table->hasIndex('idx_somework_cqrs_outbox_published_created'));
    }

    public function test_add_table_to_schema_with_custom_name(): void
    {
        $schema = new Schema();

        DbalOutboxStorage::addTableToSchema($schema, 'custom_outbox');

        self::assertTrue($schema->hasTable('custom_outbox'));
    }

    public function test_auto_setup_runs_on_first_fetch(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->expects(self::once())
            ->method('createTable');

        $this->connection->method('createSchemaManager')
            ->willReturn($schemaManager);

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connection->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $storage = new DbalOutboxStorage($this->connection, autoSetup: true);
        $storage->fetchUnpublished(10);
        // Second call should NOT trigger setup again
        $storage->fetchUnpublished(10);
    }

    public function test_auto_setup_runs_on_first_store(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->expects(self::once())
            ->method('createTable');

        $this->connection->method('createSchemaManager')
            ->willReturn($schemaManager);

        $this->connection->method('insert');

        $storage = new DbalOutboxStorage($this->connection, autoSetup: true);
        $message = new OutboxMessage('msg-auto', '{}', '{}', new DateTimeImmutable());

        $storage->store($message);
        // Second store should NOT trigger setup again
        $storage->store($message);
    }

    public function test_auto_setup_runs_on_first_mark_published(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->expects(self::once())
            ->method('createTable');

        $this->connection->method('createSchemaManager')
            ->willReturn($schemaManager);

        $this->connection->method('update')->willReturn(1);

        $storage = new DbalOutboxStorage($this->connection, autoSetup: true);
        $storage->markPublished('msg-auto');
    }

    public function test_auto_setup_disabled_does_not_run_setup(): void
    {
        $this->connection->expects(self::never())
            ->method('createSchemaManager');

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connection->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $storage->fetchUnpublished(10);
    }

    public function test_mark_published_throws_when_id_not_found(): void
    {
        $this->connection->expects(self::once())
            ->method('update')
            ->willReturn(0);

        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Outbox message "nonexistent" not found');

        $storage->markPublished('nonexistent');
    }

    public function test_mark_published_with_custom_table_name(): void
    {
        $this->connection->expects(self::once())
            ->method('update')
            ->with('custom_outbox', self::anything(), ['id' => 'msg-x'], self::anything())
            ->willReturn(1);

        $storage = new DbalOutboxStorage($this->connection, tableName: 'custom_outbox', autoSetup: false);
        $storage->markPublished('msg-x');
    }

    public function test_add_table_to_schema_returns_table_instance(): void
    {
        $schema = new Schema();

        $table = DbalOutboxStorage::addTableToSchema($schema);

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(Table::class, $table);
        self::assertSame('somework_cqrs_outbox', $table->getObjectName()->toString());
    }

    public function test_table_has_primary_key_on_id(): void
    {
        $schema = new Schema();
        DbalOutboxStorage::addTableToSchema($schema);

        $table = $schema->getTable('somework_cqrs_outbox');
        $primaryKey = $table->getPrimaryKeyConstraint();

        self::assertNotNull($primaryKey);
        self::assertSame('id', $primaryKey->getColumnNames()[0]->toString());
    }

    public function test_table_column_types(): void
    {
        $schema = new Schema();
        DbalOutboxStorage::addTableToSchema($schema);

        $table = $schema->getTable('somework_cqrs_outbox');

        self::assertInstanceOf(\Doctrine\DBAL\Types\GuidType::class, $table->getColumn('id')->getType());
        self::assertInstanceOf(\Doctrine\DBAL\Types\TextType::class, $table->getColumn('body')->getType());
        self::assertInstanceOf(\Doctrine\DBAL\Types\TextType::class, $table->getColumn('headers')->getType());
        self::assertInstanceOf(\Doctrine\DBAL\Types\StringType::class, $table->getColumn('transport_name')->getType());
        self::assertInstanceOf(\Doctrine\DBAL\Types\DateTimeImmutableType::class, $table->getColumn('created_at')->getType());
        self::assertInstanceOf(\Doctrine\DBAL\Types\DateTimeImmutableType::class, $table->getColumn('published_at')->getType());
    }

    public function test_table_nullable_columns(): void
    {
        $schema = new Schema();
        DbalOutboxStorage::addTableToSchema($schema);

        $table = $schema->getTable('somework_cqrs_outbox');

        self::assertTrue($table->getColumn('id')->getNotnull());
        self::assertTrue($table->getColumn('body')->getNotnull());
        self::assertTrue($table->getColumn('headers')->getNotnull());
        self::assertFalse($table->getColumn('transport_name')->getNotnull());
        self::assertTrue($table->getColumn('created_at')->getNotnull());
        self::assertFalse($table->getColumn('published_at')->getNotnull());
    }

    public function test_transport_name_max_length(): void
    {
        $schema = new Schema();
        DbalOutboxStorage::addTableToSchema($schema);

        $table = $schema->getTable('somework_cqrs_outbox');

        self::assertSame(190, $table->getColumn('transport_name')->getLength());
    }

    public function test_fetch_unpublished_with_null_transport(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')
            ->willReturn([
                [
                    'id' => 'msg-null-transport',
                    'body' => '{}',
                    'headers' => '{}',
                    'transport_name' => null,
                    'created_at' => '2024-01-15 10:30:00',
                ],
            ]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connection->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $messages = $storage->fetchUnpublished(10);

        self::assertCount(1, $messages);
        self::assertNull($messages[0]->transportName);
    }

    public function test_store_with_null_transport_name(): void
    {
        $message = new OutboxMessage('msg-null', '{}', '{}', new DateTimeImmutable());

        $this->connection->expects(self::once())
            ->method('insert')
            ->with(
                'somework_cqrs_outbox',
                self::callback(static function (array $data): bool {
                    self::assertNull($data['transport_name']);

                    return true;
                }),
                self::anything(),
            );

        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $storage->store($message);
    }

    public function test_default_table_name_is_somework_cqrs_outbox(): void
    {
        $message = new OutboxMessage('msg-default', '{}', '{}', new DateTimeImmutable());

        $this->connection->expects(self::once())
            ->method('insert')
            ->with('somework_cqrs_outbox', self::anything(), self::anything());

        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $storage->store($message);
    }

    public function test_index_name_includes_table_name(): void
    {
        $schema = new Schema();
        DbalOutboxStorage::addTableToSchema($schema, 'my_outbox');

        $table = $schema->getTable('my_outbox');
        self::assertTrue($table->hasIndex('idx_my_outbox_published_created'));
    }

    public function test_fetch_unpublished_orders_by_created_at_asc_then_id_asc(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $queryBuilder->expects(self::once())
            ->method('orderBy')
            ->with('created_at', 'ASC')
            ->willReturnSelf();

        $queryBuilder->expects(self::once())
            ->method('addOrderBy')
            ->with('id', 'ASC')
            ->willReturnSelf();

        $this->connection->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $storage->fetchUnpublished(10);
    }

    public function test_fetch_unpublished_uses_custom_table_name(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $queryBuilder->expects(self::once())
            ->method('from')
            ->with('custom_outbox')
            ->willReturnSelf();

        $this->connection->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $storage = new DbalOutboxStorage($this->connection, tableName: 'custom_outbox', autoSetup: false);
        $storage->fetchUnpublished(5);
    }

    public function test_fetch_unpublished_passes_limit_to_query(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $queryBuilder->expects(self::once())
            ->method('setMaxResults')
            ->with(42)
            ->willReturnSelf();

        $this->connection->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $storage->fetchUnpublished(42);
    }

    public function test_explicit_setup_prevents_auto_setup_on_subsequent_calls(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->expects(self::once())
            ->method('createTable');

        $this->connection->method('createSchemaManager')
            ->willReturn($schemaManager);

        $this->connection->method('insert');

        $storage = new DbalOutboxStorage($this->connection, autoSetup: true);
        $storage->setup();

        // After explicit setup(), store() should NOT trigger setup again
        $message = new OutboxMessage('msg-after-setup', '{}', '{}', new DateTimeImmutable());
        $storage->store($message);
    }

    public function test_fetch_unpublished_filters_where_published_at_is_null(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $queryBuilder->expects(self::once())
            ->method('where')
            ->with('published_at IS NULL')
            ->willReturnSelf();

        $this->connection->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $storage->fetchUnpublished(10);
    }

    public function test_fetch_unpublished_selects_correct_columns(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $queryBuilder->expects(self::once())
            ->method('select')
            ->with('id', 'body', 'headers', 'transport_name', 'created_at')
            ->willReturnSelf();

        $this->connection->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $storage = new DbalOutboxStorage($this->connection, autoSetup: false);
        $storage->fetchUnpublished(10);
    }

    public function test_index_columns_are_published_at_and_created_at(): void
    {
        $schema = new Schema();
        DbalOutboxStorage::addTableToSchema($schema);

        $table = $schema->getTable('somework_cqrs_outbox');
        $index = $table->getIndex('idx_somework_cqrs_outbox_published_created');

        self::assertSame(
            ['published_at', 'created_at'],
            array_map(
                static fn (\Doctrine\DBAL\Schema\Index\IndexedColumn $col): string => $col->getColumnName()->toString(),
                $index->getIndexedColumns(),
            ),
        );
    }
}
