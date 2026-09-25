<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;

use function class_exists;
use function getenv;
use function is_string;
use function method_exists;
use function substr;

/**
 * The database of the outbox tests: in-memory SQLite, or the database named by the
 * CQRS_TEST_DATABASE_URL environment variable (e.g. "pdo-pgsql://user:secret@127.0.0.1:5432/cqrs_test"),
 * which CI uses to run the same tests on PostgreSQL and MySQL.
 */
final class TestDatabase
{
    public const URL_VARIABLE = 'CQRS_TEST_DATABASE_URL';

    /** Tables the tests create; they are dropped before each test on a real database. */
    private const TABLES = ['somework_cqrs_outbox', 'app_outbox', 'outbox', 'order', self::LONG_TABLE_NAME];

    /** Long enough for PostgreSQL to have cut the name of its 0.4 index to 63 characters. */
    public const LONG_TABLE_NAME = 'app_messaging_transactional_outbox_messages_x';

    /**
     * @param LoggerInterface|null $queryLogger Receives every executed SQL statement
     * @param list<Middleware>     $middlewares
     * @param bool                 $keepTables  Whether to keep the tables of a real database (a second connection to it)
     */
    public static function connect(?LoggerInterface $queryLogger = null, array $middlewares = [], bool $keepTables = false): Connection
    {
        $url = getenv(self::URL_VARIABLE);
        $configuration = new Configuration();
        if (null !== $queryLogger) {
            $middlewares[] = new LoggingMiddleware($queryLogger);
        }
        $configuration->setMiddlewares($middlewares);

        if (!is_string($url) || '' === $url) {
            return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        }

        $connection = DriverManager::getConnection((new DsnParser())->parse($url), $configuration);
        $platform = $connection->getDatabasePlatform();

        foreach ($keepTables ? [] : self::TABLES as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS '.$platform->quoteSingleIdentifier($table));
        }

        return $connection;
    }

    public static function isSqlite(Connection $connection): bool
    {
        return $connection->getDatabasePlatform() instanceof SQLitePlatform;
    }

    /**
     * @param non-empty-string $tableName
     */
    public static function hasIndex(Connection $connection, string $tableName, string $indexName): bool
    {
        $schemaManager = $connection->createSchemaManager();

        // introspectTableByUnquotedName() exists since DBAL 4.3, where introspectTable() is deprecated.
        $table = method_exists($schemaManager, 'introspectTableByUnquotedName') // @phpstan-ignore function.alreadyNarrowedType
            ? $schemaManager->introspectTableByUnquotedName($tableName)
            : $schemaManager->introspectTable($tableName); // @phpstan-ignore method.deprecated

        return $table->hasIndex($indexName);
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

        // PostgreSQL cuts longer names to 63 characters (MySQL rejects them).
        $table->addIndex(['published_at', 'created_at'], substr('idx_'.$tableName.'_published_created', 0, 63));

        $connection->createSchemaManager()->createTable($table);
    }
}
