<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox;

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
        self::assertTrue($table->hasIndex('idx_somework_cqrs_outbox_published_created'));
    }

    public function test_table_has_primary_key_after_subscriber(): void
    {
        $schema = new Schema();
        $subscriber = new OutboxSchemaSubscriber('somework_cqrs_outbox');

        $subscriber->postGenerateSchema($this->createEventArgs($schema));

        $table = $schema->getTable('somework_cqrs_outbox');
        $pk = $table->getPrimaryKeyConstraint();
        self::assertNotNull($pk);
        self::assertSame('id', $pk->getColumnNames()[0]->toString());
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

    private function createEventArgs(Schema $schema): GenerateSchemaEventArgs
    {
        return new GenerateSchemaEventArgs(
            $this->createMock(EntityManagerInterface::class),
            $schema,
        );
    }
}
