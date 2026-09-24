<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Exception\TableDoesNotExist;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use SomeWork\CqrsBundle\Contract\OutboxStorage;

use function array_filter;
use function array_map;
use function array_values;
use function class_exists;
use function count;
use function explode;
use function get_debug_type;
use function implode;
use function is_string;
use function method_exists;
use function sha1;
use function sprintf;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;

/**
 * DBAL-backed implementation of the transactional outbox storage.
 *
 * With auto-setup enabled the table is created on first use, and columns added by newer versions
 * of the bundle are added to an existing table, but never inside an open transaction: DDL would
 * commit the caller's transaction implicitly (MySQL) or abort it (PostgreSQL). Create or upgrade
 * the table up front with "somework:cqrs:outbox:setup" or a migration (the Doctrine ORM schema
 * listener adds it to generated migrations).
 *
 * Dates are stored in UTC, so the order, the retry times and the purge cut-off do not depend on
 * the time zone of the process or on daylight saving time.
 *
 * @api
 */
final class DbalOutboxStorage implements OutboxStorage
{
    /** Columns added in 0.5.0: tables created by earlier versions lack them. */
    private const FAILURE_COLUMNS = ['attempts', 'available_at', 'failed_at', 'last_error'];

    private const PURGE_BATCH_SIZE = 1000;

    /** Process-local cache of the "table is up to date" check. */
    private bool $setupDone = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName = 'somework_cqrs_outbox',
        private readonly bool $autoSetup = true,
    ) {
    }

    public function store(OutboxMessage $message): void
    {
        $this->ensureTableExists();

        // The failure columns keep their defaults, so storing also works while an existing table
        // still waits for its upgrade.
        $this->guard(fn () => $this->connection->insert($this->tableName, [
            'id' => $message->id,
            'body' => $message->body,
            'headers' => $message->headers,
            'transport_name' => $message->transportName,
            'created_at' => self::utc($message->createdAt),
            'published_at' => null,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
            'published_at' => Types::DATETIME_IMMUTABLE,
        ]));
    }

    /**
     * @return list<OutboxMessage>
     */
    public function fetchUnpublished(int $limit): array
    {
        $this->ensureTableExists();

        $rows = $this->guard(fn (): array => $this->connection->createQueryBuilder()
            ->select('id', 'body', 'headers', 'transport_name', 'created_at', 'attempts')
            ->from($this->tableName)
            ->where('published_at IS NULL')
            ->andWhere('failed_at IS NULL')
            ->andWhere('available_at IS NULL OR available_at <= :now')
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setParameter('now', self::now(), Types::DATETIME_IMMUTABLE)
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative());

        $platform = $this->connection->getDatabasePlatform();

        return array_map(
            static fn (array $row): OutboxMessage => new OutboxMessage(
                id: (string) $row['id'],
                body: (string) $row['body'],
                headers: (string) $row['headers'],
                createdAt: self::readUtc($row['created_at'], $platform),
                transportName: null === $row['transport_name'] ? null : (string) $row['transport_name'],
                attempts: (int) $row['attempts'],
            ),
            $rows,
        );
    }

    public function markPublished(string $id): void
    {
        $this->ensureTableExists();

        $updated = $this->guard(fn (): int|string => $this->connection->createQueryBuilder()
            ->update($this->tableName)
            ->set('published_at', ':published_at')
            ->where('id = :id')
            ->andWhere('published_at IS NULL')
            ->setParameter('published_at', self::now(), Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id)
            ->executeStatement());

        if (0 === (int) $updated) {
            // Already published (e.g. by a concurrent relay): nothing to do. Unknown ids are an error.
            $this->assertExists($id, 'mark it as published');
        }
    }

    public function markFailed(string $id, int $attempts, string $error, ?DateTimeImmutable $retryAt): void
    {
        $this->ensureTableExists();

        $updated = $this->guard(fn (): int|string => $this->connection->createQueryBuilder()
            ->update($this->tableName)
            ->set('attempts', ':attempts')
            ->set('last_error', ':last_error')
            ->set('available_at', ':available_at')
            ->set('failed_at', ':failed_at')
            ->where('id = :id')
            ->andWhere('published_at IS NULL')
            ->setParameter('attempts', $attempts, Types::INTEGER)
            ->setParameter('last_error', $error)
            ->setParameter('available_at', null === $retryAt ? null : self::utc($retryAt), Types::DATETIME_IMMUTABLE)
            ->setParameter('failed_at', null === $retryAt ? self::now() : null, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id)
            ->executeStatement());

        if (0 === (int) $updated) {
            $this->assertExists($id, 'record an attempt');
        }
    }

    public function purgePublished(DateTimeImmutable $publishedBefore): int
    {
        $this->ensureTableExists();

        $deleted = 0;

        // In batches: a single DELETE of a large backlog holds its locks and grows the transaction log.
        do {
            $ids = $this->guard(fn (): array => $this->connection->createQueryBuilder()
                ->select('id')
                ->from($this->tableName)
                ->where('published_at IS NOT NULL')
                ->andWhere('published_at < :before')
                ->setParameter('before', self::utc($publishedBefore), Types::DATETIME_IMMUTABLE)
                ->setMaxResults(self::PURGE_BATCH_SIZE)
                ->executeQuery()
                ->fetchFirstColumn());

            if ([] === $ids) {
                break;
            }

            $deleted += (int) $this->guard(fn (): int|string => $this->connection->createQueryBuilder()
                ->delete($this->tableName)
                ->where('id IN (:ids)')
                ->setParameter('ids', $ids, ArrayParameterType::STRING)
                ->executeStatement());
        } while (self::PURGE_BATCH_SIZE === count($ids));

        return $deleted;
    }

    /**
     * Counts the due and the given-up messages, for monitoring.
     *
     * @return array{due: int, oldest_due: DateTimeImmutable|null, failed: int}
     */
    public function status(): array
    {
        $this->ensureTableExists();

        $due = $this->guard(fn (): array|false => $this->connection->createQueryBuilder()
            ->select('COUNT(*) AS due', 'MIN(created_at) AS oldest_due')
            ->from($this->tableName)
            ->where('published_at IS NULL')
            ->andWhere('failed_at IS NULL')
            ->andWhere('available_at IS NULL OR available_at <= :now')
            ->setParameter('now', self::now(), Types::DATETIME_IMMUTABLE)
            ->executeQuery()
            ->fetchAssociative());

        $failed = $this->guard(fn (): mixed => $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->tableName)
            ->where('published_at IS NULL')
            ->andWhere('failed_at IS NOT NULL')
            ->executeQuery()
            ->fetchOne());

        $oldestDue = false === $due ? null : ($due['oldest_due'] ?? null);

        return [
            'due' => false === $due ? 0 : (int) $due['due'],
            'oldest_due' => null === $oldestDue ? null : self::readUtc($oldestDue, $this->connection->getDatabasePlatform()),
            'failed' => (int) $failed,
        ];
    }

    /**
     * Returns the messages the relay gave up on, oldest failure first.
     *
     * @return list<array{id: string, transport_name: string|null, created_at: DateTimeImmutable, failed_at: DateTimeImmutable, attempts: int, last_error: string|null}>
     */
    public function fetchFailed(int $limit): array
    {
        $this->ensureTableExists();

        $rows = $this->guard(fn (): array => $this->connection->createQueryBuilder()
            ->select('id', 'transport_name', 'created_at', 'failed_at', 'attempts', 'last_error')
            ->from($this->tableName)
            ->where('published_at IS NULL')
            ->andWhere('failed_at IS NOT NULL')
            ->orderBy('failed_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative());

        $platform = $this->connection->getDatabasePlatform();

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'transport_name' => null === $row['transport_name'] ? null : (string) $row['transport_name'],
            'created_at' => self::readUtc($row['created_at'], $platform),
            'failed_at' => self::readUtc($row['failed_at'], $platform),
            'attempts' => (int) $row['attempts'],
            'last_error' => null === $row['last_error'] ? null : (string) $row['last_error'],
        ], $rows);
    }

    /**
     * Hands messages the relay gave up on back to it, with a fresh attempt counter.
     *
     * @param list<string> $ids The messages to requeue; all given-up messages when empty
     *
     * @return int The number of requeued messages
     */
    public function requeueFailed(array $ids = []): int
    {
        $this->ensureTableExists();

        $query = $this->connection->createQueryBuilder()
            ->update($this->tableName)
            ->set('failed_at', 'NULL')
            ->set('available_at', 'NULL')
            ->set('attempts', '0')
            ->where('published_at IS NULL')
            ->andWhere('failed_at IS NOT NULL');

        if ([] !== $ids) {
            $query->andWhere('id IN (:ids)')->setParameter('ids', $ids, ArrayParameterType::STRING);
        }

        return (int) $this->guard(static fn (): int|string => $query->executeStatement());
    }

    /**
     * Creates the outbox table, or adds the columns an existing table lacks.
     *
     * @throws \LogicException when the table must be changed inside an open transaction, or when
     *                         the database rejects the table name
     */
    public function setup(): void
    {
        $this->guard(function (): void {
            if ($this->tableExists()) {
                $this->addMissingColumns();
            } else {
                $this->assertNoTransaction('does not exist');

                try {
                    $this->connection->createSchemaManager()->createTable(self::buildTableDefinition($this->tableName));
                } catch (TableExistsException|UniqueConstraintViolationException $exception) {
                    // Created concurrently by another process (PostgreSQL may report the clash on its
                    // catalog as a unique constraint violation).
                    if (!$this->tableExists()) {
                        throw $exception;
                    }
                }
            }

            // Schema tools quote reserved words, plain queries do not: fail here, not on the first message.
            $this->connection->createQueryBuilder()->select('id')->from($this->tableName)->where('1 = 0')->executeQuery()->free();
        });

        $this->setupDone = true;
    }

    /**
     * Adds the outbox table definition to an existing Schema object.
     *
     * Useful for Doctrine schema listeners or migration generation.
     */
    public static function addTableToSchema(Schema $schema, string $tableName = 'somework_cqrs_outbox'): Table
    {
        $table = $schema->createTable($tableName);

        self::configureTable($table, $tableName);

        return $table;
    }

    private function ensureTableExists(): void
    {
        // Inside a transaction the table cannot be changed; a missing table or column then fails the query itself.
        // (The existence check is also unreliable there: schema filters and qualified names hide tables.)
        if ($this->setupDone || !$this->autoSetup || $this->connection->isTransactionActive()) {
            return;
        }

        $this->setup();
    }

    private function addMissingColumns(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $current = $this->introspectTable($schemaManager);
        $missing = array_values(array_filter(self::FAILURE_COLUMNS, static fn (string $column): bool => !$current->hasColumn($column)));

        if ([] === $missing) {
            return;
        }

        $this->assertNoTransaction(sprintf('lacks the columns %s', implode(', ', $missing)));

        $upgraded = clone $current;
        self::addColumns($upgraded, $missing);

        try {
            $schemaManager->alterTable($schemaManager->createComparator()->compareTables($current, $upgraded));
        } catch (DbalException $exception) {
            // Another process may have added them in the meantime.
            $now = $this->introspectTable($schemaManager);
            foreach ($missing as $column) {
                if (!$now->hasColumn($column)) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @param AbstractSchemaManager<AbstractPlatform> $schemaManager
     */
    private function introspectTable(AbstractSchemaManager $schemaManager): Table
    {
        // introspectTableByUnquotedName() exists since DBAL 4.3, where introspectTable() is deprecated.
        if (method_exists($schemaManager, 'introspectTableByUnquotedName')) { // @phpstan-ignore function.alreadyNarrowedType
            $parts = explode('.', $this->tableName, 2);
            $table = $parts[1] ?? $parts[0];
            $schema = isset($parts[1]) ? $parts[0] : null;

            // The configuration only allows "table" and "schema.table".
            if ('' === $table || '' === $schema) {
                throw new \LogicException(sprintf('Invalid outbox table name "%s".', $this->tableName));
            }

            return $schemaManager->introspectTableByUnquotedName($table, $schema);
        }

        try {
            return $schemaManager->introspectTable($this->tableName); // @phpstan-ignore method.deprecated
        } catch (TableDoesNotExist $exception) {
            // Before DBAL 4.3 the name is not folded like the database folds unquoted names
            // (PostgreSQL stores "OutboxMessages" as "outboxmessages").
            if (strtolower($this->tableName) === $this->tableName) {
                throw $exception;
            }

            return $schemaManager->introspectTable(strtolower($this->tableName)); // @phpstan-ignore method.deprecated
        }
    }

    private function assertNoTransaction(string $problem): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException(sprintf('The outbox table "%s" %s and cannot be changed inside an open database transaction. Run "bin/console somework:cqrs:outbox:setup" or a Doctrine migration beforehand.', $this->tableName, $problem));
        }
    }

    private function assertExists(string $id, string $action): void
    {
        $exists = $this->guard(fn (): mixed => $this->connection->createQueryBuilder()
            ->select('1')
            ->from($this->tableName)
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne());

        if (false === $exists) {
            throw new \RuntimeException(sprintf('Outbox message "%s" not found in table "%s" — cannot %s.', $id, $this->tableName, $action));
        }
    }

    /**
     * Turns the errors of a missing, outdated or unusable table into instructions.
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function guard(\Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (TableNotFoundException $exception) {
            throw new \LogicException(sprintf('The outbox table "%s" does not exist. Create it with "bin/console somework:cqrs:outbox:setup" or a Doctrine migration; it is never created inside an open transaction.', $this->tableName), 0, $exception);
        } catch (SyntaxErrorException $exception) {
            throw new \LogicException(sprintf('The database rejected a query on the outbox table "%s"; the name is probably a reserved word of this database. Choose another "somework_cqrs.outbox.table_name".', $this->tableName), 0, $exception);
        } catch (DriverException $exception) {
            // Not every driver reports an unknown column as InvalidFieldNameException (SQLite does not).
            if ($exception instanceof InvalidFieldNameException || $this->lacksFailureColumns()) {
                throw new \LogicException(sprintf('The outbox table "%s" lacks columns this version of the bundle needs (%s). Upgrade it with "bin/console somework:cqrs:outbox:setup" or a Doctrine migration.', $this->tableName, implode(', ', self::FAILURE_COLUMNS)), 0, $exception);
            }

            throw $exception;
        }
    }

    private function lacksFailureColumns(): bool
    {
        try {
            $table = $this->introspectTable($this->connection->createSchemaManager());
        } catch (\Throwable) {
            return false;
        }

        foreach (self::FAILURE_COLUMNS as $column) {
            if (!$table->hasColumn($column)) {
                return true;
            }
        }

        return false;
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function utc(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Reads a stored DATETIME as UTC. DBAL's type would parse it in the default time zone first,
     * which shifts wall-clock times that fall into a daylight saving gap there.
     */
    private static function readUtc(mixed $value, AbstractPlatform $platform): DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return new DateTimeImmutable($value->format('Y-m-d H:i:s.u'), new DateTimeZone('UTC'));
        }

        if (!is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Unexpected outbox date value of type %s.', get_debug_type($value)));
        }

        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!'.$platform->getDateTimeFormatString(), $value, $utc);

        return false === $date ? new DateTimeImmutable($value, $utc) : $date;
    }

    private function tableExists(): bool
    {
        // Not tablesExist(): it does not find "public.<table>" on PostgreSQL, and it applies the schema asset filter.
        try {
            $this->introspectTable($this->connection->createSchemaManager());
        } catch (TableDoesNotExist) {
            return false;
        }

        return true;
    }

    private static function buildTableDefinition(string $tableName): Table
    {
        $table = new Table($tableName);

        self::configureTable($table, $tableName);

        return $table;
    }

    private static function configureTable(Table $table, string $tableName): void
    {
        self::addColumns($table, ['id', 'body', 'headers', 'transport_name', 'created_at', 'published_at', ...self::FAILURE_COLUMNS]);

        // PrimaryKeyConstraint and Table::addPrimaryKeyConstraint() exist since DBAL 4.3;
        // Table::setPrimaryKey() is the only option on 4.0-4.2 (deprecated from 4.3).
        if (class_exists(PrimaryKeyConstraint::class)) {
            $table->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()
                    ->setUnquotedColumnNames('id')
                    ->create(),
            );
        } else {
            // Only reached on DBAL < 4.3, where setPrimaryKey() is not deprecated.
            $table->setPrimaryKey(['id']); // @phpstan-ignore method.deprecated
        }

        $table->addIndex(['published_at', 'created_at'], self::indexName($tableName));
    }

    /**
     * @param list<string> $columns
     */
    private static function addColumns(Table $table, array $columns): void
    {
        foreach ($columns as $column) {
            match ($column) {
                'id' => $table->addColumn('id', Types::GUID)->setNotnull(true),
                'body' => $table->addColumn('body', Types::TEXT)->setNotnull(true),
                'headers' => $table->addColumn('headers', Types::TEXT)->setNotnull(true),
                'transport_name' => $table->addColumn('transport_name', Types::STRING)->setLength(190)->setNotnull(false),
                'created_at' => $table->addColumn('created_at', Types::DATETIME_IMMUTABLE)->setNotnull(true),
                'published_at' => $table->addColumn('published_at', Types::DATETIME_IMMUTABLE)->setNotnull(false),
                // Failed attempts so far; the relay gives up after "somework_cqrs.outbox.max_attempts".
                'attempts' => $table->addColumn('attempts', Types::INTEGER)->setNotnull(true)->setDefault(0),
                // Earliest time of the next attempt after a failure (NULL: now).
                'available_at' => $table->addColumn('available_at', Types::DATETIME_IMMUTABLE)->setNotnull(false),
                // When the relay gave up on the message.
                'failed_at' => $table->addColumn('failed_at', Types::DATETIME_IMMUTABLE)->setNotnull(false),
                'last_error' => $table->addColumn('last_error', Types::TEXT)->setNotnull(false),
                default => throw new \LogicException(sprintf('Unknown outbox column "%s".', $column)),
            };
        }
    }

    /**
     * Keeps the historical "idx_<table>_published_created" name, falling back to a hashed
     * name when it would exceed the 63-character identifier limit of PostgreSQL/MySQL.
     */
    private static function indexName(string $tableName): string
    {
        $name = 'idx_'.str_replace('.', '_', $tableName).'_published_created';

        return strlen($name) <= 63 ? $name : 'idx_'.substr(sha1($tableName), 0, 16).'_published_created';
    }
}
