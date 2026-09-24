<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Types;

use function class_exists;
use function getenv;
use function is_string;

/**
 * The database of the outbox tests: in-memory SQLite, or the database named by the
 * CQRS_TEST_DATABASE_URL environment variable (e.g. "pdo-pgsql://user:secret@127.0.0.1:5432/cqrs_test"),
 * which CI uses to run the same tests on PostgreSQL and MySQL.
 */
final class TestDatabase
{
    public const URL_VARIABLE = 'CQRS_TEST_DATABASE_URL';

    /** Tables the tests create; they are dropped before each test on a real database. */
    private const TABLES = ['somework_cqrs_outbox', 'app_outbox', 'outbox', 'order'];

    public static function connect(): Connection
    {
        $url = getenv(self::URL_VARIABLE);

        if (!is_string($url) || '' === $url) {
            return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        }

        $connection = DriverManager::getConnection((new DsnParser())->parse($url));
        $platform = $connection->getDatabasePlatform();

        foreach (self::TABLES as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS '.$platform->quoteSingleIdentifier($table));
        }

        return $connection;
    }

    public static function isSqlite(Connection $connection): bool
    {
        return $connection->getDatabasePlatform() instanceof SQLitePlatform;
    }

    /**
     * Creates the outbox table as version 0.4 of the bundle did (without the failure columns).
     */
    public static function createTableOfVersion04(Connection $connection, string $tableName = 'somework_cqrs_outbox'): void
    {
        $table = new Table($tableName);
        $table->addColumn('id', Types::GUID)->setNotnull(true);
        $table->addColumn('body', Types::TEXT)->setNotnull(true);
        $table->addColumn('headers', Types::TEXT)->setNotnull(true);
        $table->addColumn('transport_name', Types::STRING)->setLength(190)->setNotnull(false);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE)->setNotnull(true);
        $table->addColumn('published_at', Types::DATETIME_IMMUTABLE)->setNotnull(false);

        if (class_exists(PrimaryKeyConstraint::class)) {
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        } else {
            $table->setPrimaryKey(['id']); // @phpstan-ignore method.deprecated
        }

        $table->addIndex(['published_at', 'created_at'], 'idx_'.$tableName.'_published_created');

        $connection->createSchemaManager()->createTable($table);
    }
}
