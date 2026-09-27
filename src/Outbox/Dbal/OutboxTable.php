<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox\Dbal;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\Table;
use SomeWork\CqrsBundle\Exception\LogicException;

use function array_filter;
use function array_keys;
use function array_map;
use function explode;
use function sprintf;

/**
 * Builds and changes schema tables with DBAL's table, column and index editors, which DBAL 4.5
 * makes the only non-deprecated way to change a table.
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
        [$table, $schema] = self::name($name);
        $editor = Table::editor()
            ->setUnquotedName($table, $schema)
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
     * uses the mutators, as Doctrine ORM's SchemaTool does. DBAL 5 removes them: this waits for
     * the schema API that Doctrine ORM will use with DBAL 5.
     *
     * @param non-empty-array<non-empty-string, ColumnDefinition> $columns
     * @param Indexes                                             $indexes
     * @param non-empty-string                                    $primaryKey
     */
    public static function addToSchema(Schema $schema, string $name, array $columns, string $primaryKey, array $indexes): Table
    {
        $table = $schema->createTable($name); // @phpstan-ignore method.deprecated
        foreach ($columns as $columnName => $definition) {
            $table->addColumn($columnName, $definition['type'], self::options($definition)); // @phpstan-ignore method.deprecated
        }
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames($primaryKey)->create()); // @phpstan-ignore method.deprecated
        foreach ($indexes as $indexName => $indexColumns) {
            $table->addIndex($indexColumns, $indexName); // @phpstan-ignore method.deprecated
        }

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

        $editor = $table->edit();
        foreach (self::indexes($indexes) as $index) {
            $editor->addIndex($index);
        }

        return $editor->create();
    }

    public static function withoutIndex(Table $table, string $name): Table
    {
        return '' === $name ? $table : $table->edit()->dropIndexByUnquotedName($name)->create();
    }

    /**
     * The configuration only allows "table" and "schema.table", without quotes.
     *
     * @return array{non-empty-string, non-empty-string|null} The table and its schema
     */
    private static function name(string $name): array
    {
        $parts = explode('.', $name, 2);
        $table = $parts[1] ?? $parts[0];
        $schema = isset($parts[1]) ? $parts[0] : null;
        if ('' === $table || '' === $schema) {
            throw new LogicException(sprintf('Invalid outbox table name "%s".', $name));
        }

        return [$table, $schema];
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
