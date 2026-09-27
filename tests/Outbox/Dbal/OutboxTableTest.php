<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox\Dbal;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\SchemaEditor;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Deprecations\Deprecation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Outbox\Dbal\DbalOutboxSchema;
use SomeWork\CqrsBundle\Outbox\Dbal\OutboxTable;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\TestDatabase;

use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_keys;
use function array_map;
use function ksort;
use function method_exists;

/**
 * OutboxTable builds tables with the schema editors of DBAL 4.5+ and with the Table mutators
 * before (and in addToSchema(), on every version): both must build the same table, and the
 * editor path must not call the APIs DBAL 4.5 deprecates.
 */
#[CoversClass(OutboxTable::class)]
final class OutboxTableTest extends TestCase
{
    /** The outbox table as DbalOutboxSchema defines it. */
    private const COLUMNS = [
        'id' => ['type' => Types::GUID, 'notnull' => true],
        'body' => ['type' => Types::TEXT, 'notnull' => true],
        'headers' => ['type' => Types::TEXT, 'notnull' => true],
        'transport_name' => ['type' => Types::STRING, 'notnull' => false, 'length' => 190],
        'created_at' => ['type' => Types::DATETIME_IMMUTABLE, 'notnull' => true],
        'published_at' => ['type' => Types::DATETIME_IMMUTABLE, 'notnull' => false],
        'attempts' => ['type' => Types::INTEGER, 'notnull' => true, 'default' => 0],
        'available_at' => ['type' => Types::DATETIME_IMMUTABLE, 'notnull' => false],
        'failed_at' => ['type' => Types::DATETIME_IMMUTABLE, 'notnull' => false],
        'last_error' => ['type' => Types::TEXT, 'notnull' => false],
        'claim_token' => ['type' => Types::STRING, 'notnull' => false, 'length' => 32],
        'claimed_at' => ['type' => Types::DATETIME_IMMUTABLE, 'notnull' => false],
        'signature' => ['type' => Types::STRING, 'notnull' => false, 'length' => 64],
    ];

    /** The columns of the table of 0.4. */
    private const COLUMNS_OF_0_4 = [
        'id' => ['type' => Types::GUID, 'notnull' => true],
        'body' => ['type' => Types::TEXT, 'notnull' => true],
        'headers' => ['type' => Types::TEXT, 'notnull' => true],
        'transport_name' => ['type' => Types::STRING, 'notnull' => false, 'length' => 190],
        'created_at' => ['type' => Types::DATETIME_IMMUTABLE, 'notnull' => true],
        'published_at' => ['type' => Types::DATETIME_IMMUTABLE, 'notnull' => false],
    ];

    private const INDEXES = [
        'idx_somework_cqrs_outbox_pending' => ['published_at', 'failed_at', 'transport_name', 'available_at', 'created_at', 'id'],
        'idx_somework_cqrs_outbox_claimed' => ['claimed_at'],
    ];

    /** What describe() returns for the outbox table. */
    private const EXPECTED = [
        'name' => 'somework_cqrs_outbox',
        'columns' => [
            'id' => ['type' => 'guid', 'length' => null, 'notnull' => true, 'default' => null],
            'body' => ['type' => 'text', 'length' => null, 'notnull' => true, 'default' => null],
            'headers' => ['type' => 'text', 'length' => null, 'notnull' => true, 'default' => null],
            'transport_name' => ['type' => 'string', 'length' => 190, 'notnull' => false, 'default' => null],
            'created_at' => ['type' => 'datetime_immutable', 'length' => null, 'notnull' => true, 'default' => null],
            'published_at' => ['type' => 'datetime_immutable', 'length' => null, 'notnull' => false, 'default' => null],
            'attempts' => ['type' => 'integer', 'length' => null, 'notnull' => true, 'default' => 0],
            'available_at' => ['type' => 'datetime_immutable', 'length' => null, 'notnull' => false, 'default' => null],
            'failed_at' => ['type' => 'datetime_immutable', 'length' => null, 'notnull' => false, 'default' => null],
            'last_error' => ['type' => 'text', 'length' => null, 'notnull' => false, 'default' => null],
            'claim_token' => ['type' => 'string', 'length' => 32, 'notnull' => false, 'default' => null],
            'claimed_at' => ['type' => 'datetime_immutable', 'length' => null, 'notnull' => false, 'default' => null],
            'signature' => ['type' => 'string', 'length' => 64, 'notnull' => false, 'default' => null],
        ],
        'primary_key' => ['id'],
        'indexes' => [
            'idx_somework_cqrs_outbox_claimed' => ['claimed_at'],
            'idx_somework_cqrs_outbox_pending' => ['published_at', 'failed_at', 'transport_name', 'available_at', 'created_at', 'id'],
        ],
    ];

    public function test_create_builds_the_outbox_table(): void
    {
        $table = OutboxTable::create('somework_cqrs_outbox', self::COLUMNS, 'id', self::INDEXES, new SchemaConfig());

        self::assertSame(self::EXPECTED, self::describe($table));
    }

    public function test_add_to_schema_builds_the_same_table_as_create(): void
    {
        $schema = new Schema();

        $table = OutboxTable::addToSchema($schema, 'somework_cqrs_outbox', self::COLUMNS, 'id', self::INDEXES);

        self::assertSame(self::EXPECTED, self::describe($table));
        self::assertSame($table, $schema->getTable('somework_cqrs_outbox'), 'The table is added to the schema.');
        self::assertSame(self::describe(OutboxTable::create('somework_cqrs_outbox', self::COLUMNS, 'id', self::INDEXES, new SchemaConfig())), self::describe($table));
    }

    public function test_the_table_of_the_storage_is_the_outbox_table(): void
    {
        self::assertSame(self::EXPECTED, self::describe(DbalOutboxStorage::addTableToSchema(new Schema())));
    }

    public function test_the_table_is_changed_in_a_copy(): void
    {
        $legacyIndex = ['idx_somework_cqrs_outbox_published_created' => ['published_at', 'created_at']];
        $table = OutboxTable::create('somework_cqrs_outbox', self::COLUMNS_OF_0_4, 'id', $legacyIndex, new SchemaConfig());
        $before = self::describe($table);

        $withColumns = OutboxTable::withColumns($table, array_intersect_key(self::COLUMNS, array_flip(DbalOutboxSchema::COLUMNS_SINCE_0_4)));
        $withIndexes = OutboxTable::withIndexes($withColumns, self::INDEXES);
        $upgraded = OutboxTable::withoutIndex($withIndexes, 'idx_somework_cqrs_outbox_published_created');

        self::assertSame(self::EXPECTED, self::describe($upgraded));
        self::assertSame($before, self::describe($table), 'The original table is unchanged.');
        self::assertNotSame($table, $withColumns);
        self::assertSame(array_keys(self::COLUMNS), array_keys(self::describe($withColumns)['columns']));
        self::assertSame(['idx_somework_cqrs_outbox_published_created'], array_keys(self::describe($withColumns)['indexes']));
        self::assertSame(
            ['idx_somework_cqrs_outbox_claimed', 'idx_somework_cqrs_outbox_pending', 'idx_somework_cqrs_outbox_published_created'],
            array_keys(self::describe($withIndexes)['indexes']),
        );
        self::assertSame($table, OutboxTable::withColumns($table, []), 'Nothing to add.');
        self::assertSame($table, OutboxTable::withIndexes($table, []), 'Nothing to add.');
    }

    #[RequiresMethod(SchemaEditor::class, 'addTable')]
    public function test_the_editors_of_dbal_4_5_build_and_change_the_table_without_deprecated_calls(): void
    {
        $legacyIndex = ['idx_somework_cqrs_outbox_published_created' => ['published_at', 'created_at']];

        $upgraded = self::withoutDeprecations(static function () use ($legacyIndex): Table {
            OutboxTable::create('somework_cqrs_outbox', self::COLUMNS, 'id', self::INDEXES, new SchemaConfig());
            $table = OutboxTable::create('somework_cqrs_outbox', self::COLUMNS_OF_0_4, 'id', $legacyIndex, new SchemaConfig());
            $table = OutboxTable::withColumns($table, array_intersect_key(self::COLUMNS, array_flip(DbalOutboxSchema::COLUMNS_SINCE_0_4)));
            $table = OutboxTable::withIndexes($table, self::INDEXES);

            return OutboxTable::withoutIndex($table, 'idx_somework_cqrs_outbox_published_created');
        });

        self::assertSame(self::EXPECTED, self::describe($upgraded));
    }

    #[Group('database')]
    #[RequiresMethod(SchemaEditor::class, 'addTable')]
    public function test_the_setup_creates_the_table_without_deprecated_calls(): void
    {
        $connection = TestDatabase::connect();

        self::withoutDeprecations(static fn () => (new DbalOutboxStorage($connection))->setup());

        self::assertTrue(TestDatabase::hasIndex($connection, 'somework_cqrs_outbox', 'idx_somework_cqrs_outbox_pending'));
        self::assertSame([], (new DbalOutboxStorage($connection))->pendingChanges());
    }

    #[Group('database')]
    #[RequiresMethod(SchemaEditor::class, 'addTable')]
    public function test_the_setup_upgrades_a_table_of_0_4_without_deprecated_calls(): void
    {
        $connection = TestDatabase::connect();
        TestDatabase::createTableOfVersion04($connection);

        self::withoutDeprecations(static fn () => (new DbalOutboxStorage($connection))->setup());

        self::assertSame([], (new DbalOutboxStorage($connection))->pendingChanges());
        self::assertTrue(TestDatabase::hasIndex($connection, 'somework_cqrs_outbox', 'idx_somework_cqrs_outbox_pending'));
        self::assertFalse(TestDatabase::hasIndex($connection, 'somework_cqrs_outbox', 'idx_somework_cqrs_outbox_published_created'), 'The index of 0.4 is dropped.');
    }

    /**
     * Runs $operation while Doctrine tracks deprecations, asserts that it triggered none, and
     * restores the state of Doctrine\Deprecations afterwards.
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private static function withoutDeprecations(\Closure $operation): mixed
    {
        $state = new \ReflectionClass(Deprecation::class);
        $saved = $state->getStaticProperties();
        try {
            Deprecation::enableTrackingDeprecations();
            $before = array_filter(Deprecation::getTriggeredDeprecations(), static fn (int $count): bool => $count > 0);
            $result = $operation();
            $after = array_filter(Deprecation::getTriggeredDeprecations(), static fn (int $count): bool => $count > 0);
        } finally {
            foreach ($saved as $name => $value) {
                $state->setStaticPropertyValue($name, $value);
            }
        }

        self::assertSame($before, $after, 'No deprecated DBAL API was called.');

        return $result;
    }

    /**
     * The table in a form that is the same on every DBAL version (4.0 to 4.5+).
     *
     * @return array{name: string, columns: array<string, array{type: string, length: int|null, notnull: bool, default: mixed}>, primary_key: list<string>, indexes: array<string, list<string>>}
     */
    private static function describe(Table $table): array
    {
        $columns = [];
        foreach ($table->getColumns() as $column) {
            $columns[self::columnName($column)] = [
                'type' => self::typeName($column),
                'length' => $column->getLength(),
                'notnull' => $column->getNotnull(),
                'default' => $column->getDefault(),
            ];
        }

        $indexes = [];
        foreach ($table->getIndexes() as $name => $index) {
            // The primary key is also listed as the index "primary".
            if ('primary' !== $name) {
                $indexes[$name] = self::indexedColumns($index);
            }
        }
        ksort($indexes);

        return [
            'name' => self::tableName($table),
            'columns' => $columns,
            'primary_key' => self::primaryKey($table),
            'indexes' => $indexes,
        ];
    }

    private static function tableName(Table $table): string
    {
        // getObjectName() exists since DBAL 4.3, where getName() is deprecated.
        return method_exists($table, 'getObjectName') // @phpstan-ignore function.alreadyNarrowedType
            ? $table->getObjectName()->toString()
            : $table->getName(); // @phpstan-ignore method.deprecated
    }

    private static function typeName(Column $column): string
    {
        // getTypeName() exists since DBAL 4.5, where getType() is deprecated.
        return method_exists($column, 'getTypeName') // @phpstan-ignore function.alreadyNarrowedType
            ? $column->getTypeName()
            : Type::getTypeRegistry()->lookupName($column->getType()); // @phpstan-ignore method.deprecated
    }

    private static function columnName(Column $column): string
    {
        return method_exists($column, 'getObjectName') // @phpstan-ignore function.alreadyNarrowedType
            ? $column->getObjectName()->toString()
            : $column->getName(); // @phpstan-ignore method.deprecated
    }

    /**
     * @return list<string>
     */
    private static function indexedColumns(Index $index): array
    {
        // getIndexedColumns() replaces getColumns() in DBAL 4.4.
        return method_exists($index, 'getIndexedColumns') // @phpstan-ignore function.alreadyNarrowedType
            ? array_map(static fn ($column): string => $column->getColumnName()->toString(), $index->getIndexedColumns())
            : $index->getColumns(); // @phpstan-ignore method.deprecated
    }

    /**
     * @return list<string>
     */
    private static function primaryKey(Table $table): array
    {
        // getPrimaryKeyConstraint() exists since DBAL 4.3, where getPrimaryKey() is deprecated.
        if (method_exists($table, 'getPrimaryKeyConstraint')) { // @phpstan-ignore function.alreadyNarrowedType
            $constraint = $table->getPrimaryKeyConstraint();

            return null === $constraint ? [] : array_map(static fn ($name): string => $name->toString(), $constraint->getColumnNames());
        }

        $primaryKey = $table->getPrimaryKey(); // @phpstan-ignore method.deprecated

        return null === $primaryKey ? [] : $primaryKey->getColumns(); // @phpstan-ignore method.deprecated
    }
}
