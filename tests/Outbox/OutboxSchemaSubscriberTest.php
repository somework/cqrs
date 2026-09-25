<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxSchemaSubscriber;

#[CoversClass(OutboxSchemaSubscriber::class)]
final class OutboxSchemaSubscriberTest extends TestCase
{
    public function test_adds_table_to_schema(): void
    {
        $schema = new Schema();
        $subscriber = new OutboxSchemaSubscriber('somework_cqrs_outbox');

        $subscriber->postGenerateSchema($this->createEventArgs($schema));

        self::assertTrue($schema->hasTable('somework_cqrs_outbox'));
    }

    public function test_skips_existing_table(): void
    {
        $schema = new Schema();
        DbalOutboxStorage::addTableToSchema($schema, 'somework_cqrs_outbox');

        self::assertTrue($schema->hasTable('somework_cqrs_outbox'));

        $subscriber = new OutboxSchemaSubscriber('somework_cqrs_outbox');
        $subscriber->postGenerateSchema($this->createEventArgs($schema));

        // No exception thrown — idempotent
        self::assertTrue($schema->hasTable('somework_cqrs_outbox'));
    }

    public function test_uses_custom_table_name(): void
    {
        $schema = new Schema();
        $subscriber = new OutboxSchemaSubscriber('custom_outbox');

        $subscriber->postGenerateSchema($this->createEventArgs($schema));

        self::assertTrue($schema->hasTable('custom_outbox'));
        self::assertFalse($schema->hasTable('somework_cqrs_outbox'));
    }

    public function test_table_has_correct_columns(): void
    {
        $schema = new Schema();
        $subscriber = new OutboxSchemaSubscriber('somework_cqrs_outbox');

        $subscriber->postGenerateSchema($this->createEventArgs($schema));

        $table = $schema->getTable('somework_cqrs_outbox');
        self::assertTrue($table->hasColumn('id'));
        self::assertTrue($table->hasColumn('body'));
        self::assertTrue($table->hasColumn('headers'));
        self::assertTrue($table->hasColumn('transport_name'));
        self::assertTrue($table->hasColumn('created_at'));
        self::assertTrue($table->hasColumn('published_at'));
    }

    public function test_table_has_index_after_subscriber(): void
    {
        $schema = new Schema();
        $subscriber = new OutboxSchemaSubscriber('somework_cqrs_outbox');

        $subscriber->postGenerateSchema($this->createEventArgs($schema));

        $table = $schema->getTable('somework_cqrs_outbox');
        self::assertTrue($table->hasIndex('idx_somework_cqrs_outbox_pending'));
    }

    public function test_table_has_primary_key_after_subscriber(): void
    {
        $schema = new Schema();
        $subscriber = new OutboxSchemaSubscriber('somework_cqrs_outbox');

        $subscriber->postGenerateSchema($this->createEventArgs($schema));

        $sql = implode(";\n", (new SQLitePlatform())->getCreateTableSQL($schema->getTable('somework_cqrs_outbox')));
        self::assertMatchesRegularExpression('/PRIMARY KEY\s*\(\s*id\s*\)/i', $sql);
    }

    public function test_multiple_calls_are_idempotent(): void
    {
        $schema = new Schema();
        $subscriber = new OutboxSchemaSubscriber('somework_cqrs_outbox');

        $subscriber->postGenerateSchema($this->createEventArgs($schema));
        $subscriber->postGenerateSchema($this->createEventArgs($schema));

        // Still only one table, no exception
        self::assertCount(1, $schema->getTables());
    }

    public function test_default_table_name(): void
    {
        $subscriber = new OutboxSchemaSubscriber();
        $schema = new Schema();

        $subscriber->postGenerateSchema($this->createEventArgs($schema));

        self::assertTrue($schema->hasTable('somework_cqrs_outbox'));
    }

    public function test_a_mysql_table_in_the_database_of_the_connection_is_added_without_the_database(): void
    {
        $schema = new Schema();

        (new OutboxSchemaSubscriber('app.outbox'))->postGenerateSchema($this->createEventArgs($schema, 'app'));

        // Like the table that the storage creates, which the schema lists without the database.
        self::assertTrue($schema->hasTable('outbox'));
        self::assertTrue($schema->getTable('outbox')->hasIndex('idx_app_outbox_pending'));
    }

    public function test_a_mysql_table_in_another_database_is_left_out(): void
    {
        $schema = new Schema();

        (new OutboxSchemaSubscriber('other.outbox'))->postGenerateSchema($this->createEventArgs($schema, 'app'));

        self::assertSame([], $schema->getTables(), 'The schema only holds the database of the connection.');
    }

    private function createEventArgs(Schema $schema, ?string $mysqlDatabase = null): GenerateSchemaEventArgs
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        if (null !== $mysqlDatabase) {
            $connection = $this->createMock(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
            $connection->method('getDatabase')->willReturn($mysqlDatabase);
            $entityManager->method('getConnection')->willReturn($connection);
        }

        return new GenerateSchemaEventArgs($entityManager, $schema);
    }
}
