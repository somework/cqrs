<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox\Dbal;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name\Parsers;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\SchemaEditor;
use Doctrine\DBAL\Schema\Table;

use function array_filter;
use function array_keys;
use function array_map;
use function class_exists;

/**
 * Builds and changes schema tables with the API of the installed DBAL version: the editors
 * of DBAL 4.5+, which deprecates the Table and Column mutators, and those mutators before.
 *
 * @phpstan-type ColumnDefinition array{type: string, notnull: bool, length?: int, default?: int}
 * @phpstan-type Indexes array<non-empty-string, non-empty-list<non-empty-string>>
 *
 * @internal
 */
final class OutboxTable
{
    /**
     * @param non-empty-array<non-empty-string, ColumnDefinition> $columns
     * @param Indexes                                             $indexes    Index name => columns
     * @param SchemaConfig                                        $config     Of the connection: default table options (charset and collation on MySQL/MariaDB), as in a migration
     * @param non-empty-string                                    $primaryKey
     */
    public static function create(string $name, array $columns, string $primaryKey, array $indexes, SchemaConfig $config): Table
    {
        if (!self::hasEditors()) {
            $table = (new Schema([], [], $config))->createTable($name); // @phpstan-ignore method.deprecated
            self::configure($table, $columns, $primaryKey, $indexes);

            return $table;
        }

        $editor = Table::editor()
            ->setName(Parsers::getOptionallyQualifiedNameParser()->parse($name))
            ->setColumns(...self::columns($columns))
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames($primaryKey)->create())
            ->setOptions($config->getDefaultTableOptions())
            ->setConfiguration($config->toTableConfiguration());
        if ([] !== $indexes) {
            $editor->setIndexes(...self::indexes($indexes));
        }

        return $editor->create();
    }

    /**
     * Adds the table to a schema that is changed in place (a Doctrine schema listener, a
     * migration). DBAL 4.5 has no replacement for Schema::createTable() there yet, so this
     * uses the mutators on every version, as Doctrine ORM's SchemaTool does.
     *
     * @param non-empty-array<non-empty-string, ColumnDefinition> $columns
     * @param Indexes                                             $indexes
     * @param non-empty-string                                    $primaryKey
     */
    public static function addToSchema(Schema $schema, string $name, array $columns, string $primaryKey, array $indexes): Table
    {
        $table = $schema->createTable($name); // @phpstan-ignore method.deprecated
        self::configure($table, $columns, $primaryKey, $indexes);

        return $table;
    }

    /**
     * @param array<non-empty-string, ColumnDefinition> $columns
     */
    public static function withColumns(Table $table, array $columns): Table
    {
        if ([] === $columns) {
            return $table;
        }
        if (!self::hasEditors()) {
            $changed = clone $table;
            self::addColumns($changed, $columns);

            return $changed;
        }

        $editor = $table->edit();
        foreach (self::columns($columns) as $column) {
            $editor->addColumn($column);
        }

        return $editor->create();
    }

    /**
     * @param Indexes $indexes Index name => columns
     */
    public static function withIndexes(Table $table, array $indexes): Table
    {
        if ([] === $indexes) {
            return $table;
        }
        if (!self::hasEditors()) {
            $changed = clone $table;
            foreach ($indexes as $name => $indexColumns) {
                $changed->addIndex($indexColumns, $name); // @phpstan-ignore method.deprecated
            }

            return $changed;
        }

        $editor = $table->edit();
        foreach (self::indexes($indexes) as $index) {
            $editor->addIndex($index);
        }

        return $editor->create();
    }

    public static function withoutIndex(Table $table, string $name): Table
    {
        if (!self::hasEditors()) {
            $changed = clone $table;
            $changed->dropIndex($name); // @phpstan-ignore method.deprecated

            return $changed;
        }

        return '' === $name ? $table : $table->edit()->dropIndexByUnquotedName($name)->create();
    }

    private static function hasEditors(): bool
    {
        // Schema::edit() and the table and column editors are complete since DBAL 4.5.
        return class_exists(SchemaEditor::class);
    }

    /**
     * @param non-empty-array<non-empty-string, ColumnDefinition> $columns
     * @param Indexes                                             $indexes
     * @param non-empty-string                                    $primaryKey
     */
    private static function configure(Table $table, array $columns, string $primaryKey, array $indexes): void
    {
        self::addColumns($table, $columns);

        // PrimaryKeyConstraint and Table::addPrimaryKeyConstraint() exist since DBAL 4.3;
        // Table::setPrimaryKey() is the only option on 4.0-4.2 (deprecated from 4.3).
        if (class_exists(PrimaryKeyConstraint::class)) {
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames($primaryKey)->create()); // @phpstan-ignore method.deprecated
        } else {
            $table->setPrimaryKey([$primaryKey]); // @phpstan-ignore method.deprecated
        }

        foreach ($indexes as $name => $indexColumns) {
            $table->addIndex($indexColumns, $name); // @phpstan-ignore method.deprecated
        }
    }

    /**
     * @param array<non-empty-string, ColumnDefinition> $columns
     */
    private static function addColumns(Table $table, array $columns): void
    {
        foreach ($columns as $name => $definition) {
            $table->addColumn($name, $definition['type'], self::options($definition)); // @phpstan-ignore method.deprecated
        }
    }

    /**
     * @param ColumnDefinition $definition
     *
     * @return array{notnull: bool, length?: int, default?: int}
     */
    private static function options(array $definition): array
    {
        return array_filter(
            ['notnull' => $definition['notnull'], 'length' => $definition['length'] ?? null, 'default' => $definition['default'] ?? null],
            static fn (int|bool|null $value): bool => null !== $value,
        );
    }

    /**
     * @param non-empty-array<non-empty-string, ColumnDefinition> $columns
     *
     * @return non-empty-list<Column>
     */
    private static function columns(array $columns): array
    {
        $created = [];
        foreach ($columns as $name => $definition) {
            $editor = Column::editor()
                ->setUnquotedName($name)
                ->setTypeName($definition['type'])
                ->setNotNull($definition['notnull']);
            if (isset($definition['length'])) {
                $editor->setLength($definition['length']);
            }
            if (isset($definition['default'])) {
                $editor->setDefaultValue($definition['default']);
            }
            $created[] = $editor->create();
        }

        return $created;
    }

    /**
     * @param non-empty-array<non-empty-string, non-empty-list<non-empty-string>> $indexes
     *
     * @return non-empty-list<Index>
     */
    private static function indexes(array $indexes): array
    {
        return array_map(
            static fn (string $name, array $indexColumns): Index => Index::editor()->setUnquotedName($name)->setUnquotedColumnNames(...$indexColumns)->create(),
            array_keys($indexes),
            $indexes,
        );
    }
}
