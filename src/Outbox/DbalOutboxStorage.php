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
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Exception\TableDoesNotExist;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use SomeWork\CqrsBundle\Contract\OutboxStorage;

use function array_column;
use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function class_exists;
use function count;
use function explode;
use function get_debug_type;
use function implode;
use function in_array;
use function is_string;
use function method_exists;
use function microtime;
use function min;
use function random_int;
use function sha1;
use function sprintf;
use function str_contains;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;
use function usleep;

use const ARRAY_FILTER_USE_KEY;

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

    /** Seconds a change of an existing table by the setup command waits for the transactions that lock it. */
    private const DDL_LOCK_TIMEOUT = 5;

    /** The same for the automatic setup, which runs every time a table lacks columns: writes queue behind the change. */
    private const AUTO_DDL_LOCK_TIMEOUT = 1;

    /**
     * Indexes by name suffix. "pending" serves the relay: per transport, the pending rows
     * (published_at and failed_at NULL), new ones by created_at (available_at NULL), retries by
     * available_at. The purge uses its first column. (0.4 had an index on published_at and
     * created_at instead.).
     *
     * @var array<string, non-empty-list<string>>
     */
    private const INDEXES = [
        'pending' => ['published_at', 'failed_at', 'transport_name', 'available_at', 'created_at', 'id'],
    ];

    /** Seconds the setup command waits for another setup to finish. */
    private const SETUP_LOCK_TIMEOUT = 600;

    /** Seconds a process that finds the table unusable (e.g. the relay) waits for another setup to finish. */
    private const AUTO_SETUP_LOCK_TIMEOUT = 30;

    /** Rotates the order of transports whose next rows tie. */
    private int $ties;

    /** Process-local cache of the "table is up to date" check. */
    private bool $setupDone = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName = 'somework_cqrs_outbox',
        private readonly bool $autoSetup = true,
    ) {
        $this->ties = random_int(0, 1 << 20);
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
     * @param list<string|null> $excludedTransports
     *
     * @return list<OutboxMessage>
     */
    public function fetchUnpublished(int $limit, array $excludedTransports = []): array
    {
        $this->ensureTableExists();

        // Transports take turns, so the backlog of one (e.g. after an outage) does not hold up the
        // others; each is queried on its own part of the index, a paused one is not even read.
        $rows = $this->dueRows($limit, $this->transportsExcept($excludedTransports));

        $platform = $this->connection->getDatabasePlatform();

        return array_map(
            static fn (array $row): OutboxMessage => new OutboxMessage(
                id: (string) $row['id'],
                body: (string) $row['body'],
                headers: (string) $row['headers'],
                createdAt: self::readUtc($row['created_at'], $platform),
                transportName: null === $row['transport_name'] ? null : (string) $row['transport_name'],
                attempts: (int) $row['attempts'],
                lastError: null === $row['last_error'] ? null : (string) $row['last_error'],
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
            // The relay records every attempt before it runs: a published message has no failure left.
            ->set('failed_at', 'NULL')
            ->set('last_error', 'NULL')
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

    public function recordAttempt(string $id, int $attempts, string $error, ?DateTimeImmutable $retryAt, ?int $previousAttempts = null): bool
    {
        $this->ensureTableExists();

        $query = $this->connection->createQueryBuilder()
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
            ->setParameter('id', $id);

        if (null !== $previousAttempts) {
            // A claim: nobody else recorded an attempt or gave the message up since the caller read it.
            $query->andWhere('attempts = :previous_attempts')
                ->andWhere('failed_at IS NULL')
                ->setParameter('previous_attempts', $previousAttempts, Types::INTEGER);
        }

        if (0 !== (int) $this->guard(static fn (): int|string => $query->executeStatement())) {
            return true;
        }

        $this->assertExists($id, 'record an attempt');

        // A claim that counts a new attempt or gives the message up always changes the row: it was not matched.
        if (null !== $previousAttempts && ($previousAttempts !== $attempts || null === $retryAt)) {
            return false;
        }

        // MySQL counts changed rows, not matched ones: a row that already holds these values was matched.
        $row = $this->guard(fn (): array|false => $this->connection->createQueryBuilder()
            ->select('attempts', 'last_error', 'published_at', 'failed_at')
            ->from($this->tableName)
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative());

        return false !== $row
            && null === $row['published_at']
            && (null === $retryAt) === (null !== $row['failed_at'])
            && $attempts === (int) $row['attempts']
            && $error === $row['last_error'];
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
     * Counts the due, the retrying and the given-up messages, for monitoring.
     *
     * "Retrying" messages were attempted at least once and are neither published nor given up.
     *
     * @return array{due: int, oldest_due: DateTimeImmutable|null, retrying: int, oldest_retrying: DateTimeImmutable|null, failed: int}
     */
    public function status(): array
    {
        // Monitoring only reads: it never changes the table (an upgrade may be running elsewhere).
        $new = $this->guard(fn (): array|false => $this->pending()
            ->select('COUNT(*) AS due', 'MIN(created_at) AS since')
            ->andWhere('available_at IS NULL')
            ->executeQuery()
            ->fetchAssociative());

        // A postponed message waits since its retry time, not since it was stored.
        $retries = $this->guard(fn (): array|false => $this->pending()
            ->select('COUNT(*) AS due', 'MIN(available_at) AS since')
            ->andWhere('available_at <= :now')
            ->setParameter('now', self::now(), Types::DATETIME_IMMUTABLE)
            ->executeQuery()
            ->fetchAssociative());

        $retrying = $this->guard(fn (): array|false => $this->pending()
            ->select('COUNT(*) AS retrying', 'MIN(created_at) AS since')
            ->andWhere('available_at IS NOT NULL')
            ->executeQuery()
            ->fetchAssociative());

        $failed = $this->guard(fn (): mixed => $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->tableName)
            ->where('published_at IS NULL')
            ->andWhere('failed_at IS NOT NULL')
            ->executeQuery()
            ->fetchOne());

        $platform = $this->connection->getDatabasePlatform();
        $since = static fn (array|false $row): ?DateTimeImmutable => false === $row || null === $row['since'] ? null : self::readUtc($row['since'], $platform);
        $oldestDue = array_filter([$since($new), $since($retries)]);

        return [
            'due' => (false === $new ? 0 : (int) $new['due']) + (false === $retries ? 0 : (int) $retries['due']),
            'oldest_due' => [] === $oldestDue ? null : min($oldestDue),
            'retrying' => false === $retrying ? 0 : (int) $retrying['retrying'],
            'oldest_retrying' => $since($retrying),
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
     * Hands messages the relay gave up on back to it, with a fresh attempt counter and no last error.
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
            ->set('last_error', 'NULL')
            ->where('published_at IS NULL')
            ->andWhere('failed_at IS NOT NULL');

        if ([] !== $ids) {
            $query->andWhere('id IN (:ids)')->setParameter('ids', $ids, ArrayParameterType::STRING);
        }

        return (int) $this->guard(static fn (): int|string => $query->executeStatement());
    }

    /**
     * Creates the outbox table, or brings an existing one up to date: adds the columns and the
     * index it lacks, rebuilds an index an interrupted build left invalid, and drops the index of
     * 0.4. It waits up to 10 minutes for another setup to finish.
     *
     * @throws \LogicException   when the table must be changed inside an open transaction, or when
     *                           the database rejects the table name
     * @throws \RuntimeException when another process keeps the table locked, or builds its index
     */
    public function setup(): void
    {
        $this->guard(fn () => $this->prepareTable(true));

        $this->setupDone = true;
    }

    /**
     * What the setup command still has to change, e.g. "the index "idx_somework_cqrs_outbox_pending"
     * is missing"; empty when the table is up to date. The automatic setup only creates the table
     * and adds missing columns: indexes are left to the setup command, which may take long.
     *
     * @return list<string>
     */
    public function pendingChanges(): array
    {
        $plan = $this->guard(fn (): ?array => $this->inDatabaseOfTable(fn (): ?array => $this->plan()));
        if (null === $plan) {
            return [];
        }
        if ($plan['create']) {
            return ['the table does not exist'];
        }

        $changes = [] === $plan['columns'] ? [] : [sprintf('the columns %s are missing', implode(', ', $plan['columns']))];
        foreach (array_keys($plan['indexes']) as $suffix) {
            $name = self::indexName($this->tableName, $suffix);
            $changes[] = match (true) {
                in_array(strtolower($name), $plan['building'], true) => sprintf('the index "%s" is being built', $name),
                in_array(strtolower($name), $plan['invalid'], true) => sprintf('the index "%s" is invalid (its build was interrupted)', $name),
                default => sprintf('the index "%s" is missing', $name),
            };
        }
        if (null !== $plan['legacy']) {
            $changes[] = sprintf('the index "%s" of version 0.4 is still there', $plan['legacy']);
        }

        return $changes;
    }

    /**
     * Creates or upgrades the table. The usual case, a table that is up to date, takes no lock.
     *
     * @param bool $explicit True for the setup command, which makes every change. The automatic
     *                       setup only makes the table usable: it creates it, or adds the columns it
     *                       lacks within a second; it never builds or drops an index, which takes
     *                       long on a big table (the health check reports a missing one)
     */
    private function prepareTable(bool $explicit): void
    {
        $this->inDatabaseOfTable(function () use ($explicit): void {
            $plan = $this->plan();

            if (null === $plan) {
                return;
            }

            if ($explicit) {
                $this->whileLocked(fn () => $this->upgrade(), self::SETUP_LOCK_TIMEOUT);
            } elseif ($plan['create'] || [] !== $plan['columns']) {
                $this->makeUsable($plan);
            }
        });

        // Schema tools quote reserved words, plain queries do not: fail here, not on the first message.
        $this->connection->createQueryBuilder()->select('id')->from($this->tableName)->where('1 = 0')->executeQuery()->free();
    }

    /**
     * What the table lacks, or null when it is up to date.
     *
     * @return array{create: bool, columns: list<string>, indexes: array<string, non-empty-list<string>>, invalid: list<string>, building: list<string>, legacy: string|null}|null
     */
    private function plan(): ?array
    {
        try {
            $table = $this->introspectTable($this->connection->createSchemaManager());
        } catch (TableDoesNotExist) {
            return ['create' => true, 'columns' => [], 'indexes' => [], 'invalid' => [], 'building' => [], 'legacy' => null];
        }

        $invalid = $this->invalidIndexes();
        $plan = [
            'create' => false,
            'columns' => array_values(array_filter(self::FAILURE_COLUMNS, static fn (string $column): bool => !$table->hasColumn($column))),
            'indexes' => array_filter(self::INDEXES, fn (string $suffix): bool => !$table->hasIndex(self::indexName($this->tableName, $suffix)) || in_array(strtolower(self::indexName($this->tableName, $suffix)), $invalid, true), ARRAY_FILTER_USE_KEY),
            'invalid' => $invalid,
            'building' => [] === $invalid ? [] : $this->indexesBeingBuilt(),
            'legacy' => $this->legacyIndexName($table),
        ];

        return [] === $plan['columns'] && [] === $plan['indexes'] && null === $plan['legacy'] ? null : $plan;
    }

    /**
     * Creates the table, or adds the columns it lacks, for a process that cannot work without them
     * (the automatic setup). It waits 30 seconds for another setup, and 1 second for the
     * transactions that lock the table.
     *
     * @param array{create: bool, columns: list<string>} $plan
     */
    private function makeUsable(array $plan): void
    {
        $this->assertNoTransaction($plan['create'] ? 'does not exist' : sprintf('lacks the columns %s', implode(', ', $plan['columns'])));

        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->whileLocked(fn () => $this->withLockTimeout(fn () => $this->addMissingColumns(), self::AUTO_DDL_LOCK_TIMEOUT), self::AUTO_SETUP_LOCK_TIMEOUT);

            return;
        }

        // One transaction for the lock, the lock timeout and the change: none of them outlives it,
        // also not behind a pooler in transaction mode (PgBouncer) that hands the connection on.
        $this->withLockTimeout(function (): void {
            if (!$this->pollLock('SELECT pg_try_advisory_xact_lock(hashtext(?))', self::AUTO_SETUP_LOCK_TIMEOUT, false)) {
                throw $this->setupRunsElsewhere(self::AUTO_SETUP_LOCK_TIMEOUT);
            }
            $this->addMissingColumns();
        }, self::AUTO_DDL_LOCK_TIMEOUT);
    }

    /**
     * Creates the table, or adds the columns it lacks; runs while holding the setup lock.
     */
    private function addMissingColumns(): void
    {
        // Another process may have done it while this one waited for the lock.
        $plan = $this->plan();
        if (null === $plan) {
            return;
        }

        $schemaManager = $this->connection->createSchemaManager();
        if ($plan['create']) {
            $schemaManager->createTable(self::buildTableDefinition($this->tableName));

            return;
        }
        if ([] === $plan['columns']) {
            return;
        }

        $current = $this->introspectTable($schemaManager);
        $upgraded = clone $current;
        self::addColumns($upgraded, $plan['columns']);
        $schemaManager->alterTable($schemaManager->createComparator()->compareTables($current, $upgraded));
    }

    /**
     * Brings the table up to date (the setup command); runs while holding the setup lock.
     */
    private function upgrade(): void
    {
        // Another process may have done it while this one waited for the lock.
        $plan = $this->plan();
        if (null === $plan) {
            return;
        }

        if ($plan['create']) {
            $this->assertNoTransaction('does not exist');
            $this->connection->createSchemaManager()->createTable(self::buildTableDefinition($this->tableName));

            return;
        }

        $this->assertNoTransaction([] === $plan['columns'] ? 'needs the index of this version' : sprintf('lacks the columns %s', implode(', ', $plan['columns'])));

        $building = array_values(array_filter(array_keys($plan['indexes']), fn (string $suffix): bool => in_array(strtolower(self::indexName($this->tableName, $suffix)), $plan['building'], true)));
        if ([] !== $building) {
            // e.g. a migration: dropping its index would fail once it is done, and throw its work away.
            throw new \RuntimeException(sprintf('Another process is building the index "%s" of the outbox table "%s". Run "bin/console somework:cqrs:outbox:setup" again once it has finished.', self::indexName($this->tableName, $building[0]), $this->tableName));
        }

        $schemaManager = $this->connection->createSchemaManager();
        $current = $this->introspectTable($schemaManager);
        $concurrently = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;

        $upgraded = clone $current;
        self::addColumns($upgraded, $plan['columns']);
        if (!$concurrently) {
            foreach ($plan['indexes'] as $suffix => $columns) {
                $upgraded->addIndex($columns, self::indexName($this->tableName, $suffix));
            }
        }
        $this->withLockTimeout(static fn () => $schemaManager->alterTable($schemaManager->createComparator()->compareTables($current, $upgraded)), self::DDL_LOCK_TIMEOUT);

        if ($concurrently) {
            foreach ($plan['indexes'] as $suffix => $columns) {
                $name = self::indexName($this->tableName, $suffix);
                // A build that was interrupted leaves an invalid index behind, which PostgreSQL does not use.
                if (in_array(strtolower($name), $plan['invalid'], true)) {
                    $this->connection->executeStatement(sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', $this->qualifiedIndexName($name)));
                }
                $this->createIndexConcurrently($name, $columns);
            }
        }

        // Only now that the new index exists: the relay is never left without one.
        if (null !== $plan['legacy']) {
            if ($concurrently) {
                $this->connection->executeStatement(sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', $this->qualifiedIndexName($plan['legacy'])));
            } else {
                $withoutLegacyIndex = clone $upgraded;
                $withoutLegacyIndex->dropIndex($plan['legacy']);
                $this->withLockTimeout(static fn () => $schemaManager->alterTable($schemaManager->createComparator()->compareTables($upgraded, $withoutLegacyIndex)), self::DDL_LOCK_TIMEOUT);
            }
        }
    }

    /**
     * The names (lower case) of the indexes of this table that are not valid (PostgreSQL only):
     * an interrupted CREATE INDEX CONCURRENTLY leaves one behind, and one being built is not valid yet.
     *
     * @return list<string>
     */
    private function invalidIndexes(): array
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return [];
        }

        // to_regclass() resolves the name like the queries do, along the search path.
        return array_map(static fn (mixed $name): string => strtolower((string) $name), $this->connection->fetchFirstColumn(
            'SELECT c.relname FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE NOT i.indisvalid AND i.indrelid = to_regclass(?)',
            [$this->tableName],
        ));
    }

    /**
     * The names (lower case) of the indexes of this table that another process builds right now
     * (PostgreSQL only; without the privileges of pg_read_all_stats, only the builds of this user).
     *
     * @return list<string>
     */
    private function indexesBeingBuilt(): array
    {
        return array_map(static fn (mixed $name): string => strtolower((string) $name), $this->connection->fetchFirstColumn(
            'SELECT c.relname FROM pg_stat_progress_create_index p JOIN pg_class c ON c.oid = p.index_relid WHERE p.relid = to_regclass(?) AND p.pid <> pg_backend_pid()',
            [$this->tableName],
        ));
    }

    /**
     * Changes the table only if its lock can be taken within $seconds: waiting behind a long
     * transaction on the table would block every write queued behind the change.
     *
     * @param \Closure(): void $change
     */
    private function withLockTimeout(\Closure $change, int $seconds): void
    {
        $platform = $this->connection->getDatabasePlatform();

        try {
            if ($platform instanceof PostgreSQLPlatform) {
                // SET LOCAL ends with the transaction (DDL is transactional on PostgreSQL), also behind a pooler.
                $this->connection->transactional(function () use ($change, $seconds): void {
                    $this->connection->executeStatement(sprintf("SET LOCAL lock_timeout = '%ds'", $seconds));
                    $change();
                });
            } elseif ($platform instanceof AbstractMySQLPlatform) {
                $previous = (int) $this->connection->fetchOne('SELECT @@SESSION.lock_wait_timeout');
                $this->connection->executeStatement(sprintf('SET SESSION lock_wait_timeout = %d', $seconds));

                try {
                    $change();
                } finally {
                    $this->connection->executeStatement(sprintf('SET SESSION lock_wait_timeout = %d', $previous));
                }
            } else {
                $change();
            }
        } catch (DriverException $exception) {
            // MySQL reports lock_wait_timeout as such; DBAL does not convert PostgreSQL's lock_not_available (55P03).
            if (!$exception instanceof LockWaitTimeoutException && '55P03' !== $exception->getSQLState()) {
                throw $exception;
            }

            throw new \RuntimeException(sprintf('The outbox table "%s" could not be changed: a transaction kept it locked for more than %d second(s). Run "bin/console somework:cqrs:outbox:setup" when the table is less busy.', $this->tableName, $seconds), 0, $exception);
        }
    }

    /**
     * Holds a database lock while $setup runs, so processes that start at the same time do not
     * change the table concurrently. It is a session lock: run the setup command over a direct
     * connection, not through a pooler in transaction mode (PgBouncer). Inside a transaction, where
     * the table cannot be changed anyway, and on SQLite no lock is taken.
     *
     * @param \Closure(): void $setup
     * @param int              $timeout Seconds to wait for another setup to finish
     */
    private function whileLocked(\Closure $setup, int $timeout): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($this->connection->isTransactionActive() || !($platform instanceof PostgreSQLPlatform || $platform instanceof AbstractMySQLPlatform)) {
            $setup();

            return;
        }

        // Polled, not waited for: on PostgreSQL, CREATE INDEX CONCURRENTLY waits for every running
        // statement, so a process blocked in pg_advisory_lock() would deadlock with the one holding
        // the lock. On MySQL, in short waits, so that a second signal stops the process.
        $locked = $platform instanceof PostgreSQLPlatform
            ? $this->pollLock('SELECT pg_try_advisory_lock(hashtext(?))', $timeout, false)
            : $this->pollLock('SELECT GET_LOCK(?, 1)', $timeout, true);
        if (!$locked) {
            throw $this->setupRunsElsewhere($timeout);
        }

        try {
            $setup();
        } finally {
            $this->connection->executeQuery($platform instanceof PostgreSQLPlatform ? 'SELECT pg_advisory_unlock(hashtext(?))' : 'SELECT RELEASE_LOCK(?)', [$this->setupLockName()])->free();
        }
    }

    /**
     * Runs $sql, which returns true or 1 once it got the setup lock, until $timeout seconds passed.
     *
     * @param bool $waits Whether $sql waits for the lock itself
     */
    private function pollLock(string $sql, int $timeout, bool $waits): bool
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            if (1 === (int) $this->connection->fetchOne($sql, [$this->setupLockName()])) {
                return true;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            if (!$waits) {
                usleep(random_int(50_000, 250_000));
            }
        }
    }

    private function setupLockName(): string
    {
        return 'somework_cqrs_outbox_setup_'.substr(sha1($this->tableName), 0, 16);
    }

    private function setupRunsElsewhere(int $timeout): \RuntimeException
    {
        return new \RuntimeException(sprintf('Another process has been setting up the outbox table "%s" for more than %d seconds. Run "bin/console somework:cqrs:outbox:setup" to wait for it.', $this->tableName, $timeout));
    }

    /**
     * Runs $operation with the database of the table selected, so that DBAL's schema manager, which
     * only looks into the current database, finds a "database.table" of MySQL.
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function inDatabaseOfTable(\Closure $operation): mixed
    {
        $parts = explode('.', $this->tableName, 2);
        if (!isset($parts[1]) || !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return $operation();
        }

        $current = $this->connection->getDatabase();
        if (null !== $current && strtolower($current) === strtolower($parts[0])) {
            return $operation();
        }
        if (null === $current) {
            throw new \LogicException(sprintf('The outbox table "%s" can only be set up when the connection selects a database (its "dbname").', $this->tableName));
        }

        $use = static fn (string $database): string => 'USE `'.str_replace('`', '``', $database).'`';
        $this->connection->executeStatement($use($parts[0]));

        try {
            return $operation();
        } finally {
            $this->connection->executeStatement($use($current));
        }
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

    /**
     * The due rows of the given transports, taking turns between them. For each transport: the new
     * rows first, in the order they were stored, then the rows whose retry time has passed, in the
     * order of their retry time.
     *
     * @param list<string|null> $transports null stands for the rows without a transport name
     *
     * @return list<array<string, mixed>>
     */
    private function dueRows(int $limit, array $transports): array
    {
        $queues = [];
        foreach ($transports as $transport) {
            $due = $this->dueIds($transport, $limit, 'available_at IS NULL', ['available_at'], ['created_at', 'id'], 'created_at');
            if (count($due) < $limit) {
                $due = [...$due, ...$this->dueIds($transport, $limit - count($due), 'available_at <= :now', [], ['available_at', 'created_at', 'id'], 'available_at')];
            }
            if ([] !== $due) {
                $queues[] = $due;
            }
        }

        // The transport whose next row has waited longest goes first, so the oldest due rows go out
        // first across transports. When more transports have a backlog than a fetch has rows, one
        // whose rows are newer waits until the older rows are served. Dates come back in one fixed
        // format, so they compare as strings.
        // Rows stored within the same second tie: their transports take turns too, starting at a
        // different one in every fetch (and in every process).
        if ([] !== $queues) {
            $start = $this->ties % count($queues);
            // The next fetch starts after the transports this one serves first.
            $this->ties += min($limit, count($queues));
            $queues = [...array_slice($queues, $start), ...array_slice($queues, 0, $start)];
        }
        usort($queues, static fn (array $a, array $b): int => $a[0][1] <=> $b[0][1]);
        $queues = array_map(static fn (array $queue): array => array_column($queue, 0), $queues);

        $ids = [];
        for ($position = 0; count($ids) < $limit; ++$position) {
            $taken = false;
            foreach ($queues as $queue) {
                if (isset($queue[$position])) {
                    $ids[] = $queue[$position];
                    $taken = true;
                    if (count($ids) === $limit) {
                        break 2;
                    }
                }
            }
            if (!$taken) {
                break;
            }
        }

        if ([] === $ids) {
            return [];
        }

        // Only the chosen rows are read in full (bodies can be large); a row that another relay
        // claimed in the meantime is no longer due and is left out.
        $query = $this->pending()
            ->andWhere('id IN (:ids)')
            ->andWhere('available_at IS NULL OR available_at <= :now')
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->setParameter('now', self::now(), Types::DATETIME_IMMUTABLE);
        $rows = [];
        foreach ($this->guard(static fn (): array => $query->executeQuery()->fetchAllAssociative()) as $row) {
            $rows[strtolower((string) $row['id'])] = $row;
        }

        $due = [];
        foreach ($ids as $id) {
            // Published, given up or claimed by another relay in the meantime: skipped.
            if (isset($rows[strtolower($id)])) {
                $due[] = $rows[strtolower($id)];
            }
        }

        return $due;
    }

    /**
     * Ids of the due rows of one transport, in index order (the index covers the query), with the
     * time since which each row is due.
     *
     * @param list<string> $nullColumns Index columns after transport_name the condition restricts to NULL
     * @param list<string> $order
     *
     * @return list<array{string, string}>
     */
    private function dueIds(?string $transport, int $limit, string $condition, array $nullColumns, array $order, string $dueSince): array
    {
        $query = $this->pending()->select('id', $dueSince.' AS due_since')->andWhere($condition)->setMaxResults($limit);
        if (null === $transport) {
            $query->andWhere('transport_name IS NULL');
        } else {
            $query->andWhere('transport_name = :transport')->setParameter('transport', $transport);
        }
        if (str_contains($condition, ':now')) {
            $query->setParameter('now', self::now(), Types::DATETIME_IMMUTABLE);
        }

        $query = $this->ordered($query, ['published_at', 'failed_at', 'transport_name', ...$nullColumns], $order);

        return array_map(static fn (array $row): array => [(string) $row['id'], (string) $row['due_since']], $this->guard(static fn (): array => $query->executeQuery()->fetchAllAssociative()));
    }

    /**
     * The transports that have pending rows, except the excluded ones; null stands for the rows
     * without a transport name. Found by skipping through the index, one probe per transport,
     * instead of reading the pending rows.
     *
     * @param list<string|null> $excludedTransports
     *
     * @return list<string|null>
     */
    private function transportsExcept(array $excludedTransports): array
    {
        $transports = in_array(null, $excludedTransports, true) ? [] : [null];
        $previous = null;

        while (true) {
            $query = $this->ordered($this->pending()->select('transport_name')->andWhere('transport_name IS NOT NULL'), ['published_at', 'failed_at'], ['transport_name'])
                ->setMaxResults(1);
            if (null !== $previous) {
                $query->andWhere('transport_name > :previous')->setParameter('previous', $previous);
            }

            $name = $this->guard(static fn (): mixed => $query->executeQuery()->fetchOne());
            if (!is_string($name)) {
                break;
            }

            if (!in_array($name, $excludedTransports, true)) {
                $transports[] = $name;
            }
            $previous = $name;
        }

        return $transports;
    }

    /**
     * Messages that are neither published nor given up.
     */
    private function pending(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'body', 'headers', 'transport_name', 'created_at', 'available_at', 'attempts', 'last_error')
            ->from($this->tableName)
            ->where('published_at IS NULL')
            ->andWhere('failed_at IS NULL');
    }

    /**
     * Orders by an index. MySQL treats columns filtered with IS NULL or "=" as constant and sorts
     * when they appear in ORDER BY; PostgreSQL only walks the index when they do.
     *
     * @param list<string> $nullColumns Leading index columns the query restricts to one value
     * @param list<string> $columns     The order
     */
    private function ordered(QueryBuilder $query, array $nullColumns, array $columns): QueryBuilder
    {
        $platform = $this->connection->getDatabasePlatform();

        foreach ([...($platform instanceof PostgreSQLPlatform ? $nullColumns : []), ...$columns] as $column) {
            $query->addOrderBy($column, 'ASC');
        }

        return $query;
    }

    private function ensureTableExists(): void
    {
        // Inside a transaction the table cannot be changed; a missing table or column then fails the query itself.
        // (The existence check is also unreliable there: schema filters and qualified names hide tables.)
        if ($this->setupDone || !$this->autoSetup || $this->connection->isTransactionActive()) {
            return;
        }

        $this->guard(fn () => $this->prepareTable(false));
        $this->setupDone = true;
    }

    /**
     * @param list<string> $columns
     */
    private function createIndexConcurrently(string $name, array $columns): void
    {
        // A statement timeout of the role would cancel a long build on every run.
        $previousTimeout = (string) $this->connection->fetchOne('SHOW statement_timeout');
        $this->connection->executeStatement('SET statement_timeout = 0');

        try {
            $this->connection->executeStatement(sprintf('CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)', $name, $this->tableName, implode(', ', $columns)));
        } catch (DbalException $exception) {
            // A failed concurrent build leaves an invalid index behind, which the next setup would take for done.
            $this->connection->executeStatement(sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', $this->qualifiedIndexName($name)));

            throw $exception;
        } finally {
            $this->connection->executeStatement(sprintf('SET statement_timeout = %s', $this->connection->quote($previousTimeout)));
        }
    }

    /**
     * Indexes live in the schema of their table.
     */
    private function qualifiedIndexName(string $index): string
    {
        $parts = explode('.', $this->tableName, 2);

        return isset($parts[1]) ? $parts[0].'.'.$index : $index;
    }

    /**
     * The index of 0.4 on (published_at, created_at), if the table still has it. 0.4 named it
     * "idx_<table>_published_created", which PostgreSQL cut to 63 characters.
     */
    private function legacyIndexName(Table $table): ?string
    {
        $name = 'idx_'.str_replace('.', '_', $this->tableName).'_published_created';

        foreach ([$name, substr($name, 0, 63)] as $candidate) {
            if ($table->hasIndex($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * On MySQL, a "database.table" must be introspected with its database selected (inDatabaseOfTable()).
     *
     * @param AbstractSchemaManager<AbstractPlatform> $schemaManager
     */
    private function introspectTable(AbstractSchemaManager $schemaManager): Table
    {
        $parts = explode('.', $this->tableName, 2);
        $table = $parts[1] ?? $parts[0];
        // MySQL's schema manager only looks into the current database.
        $schema = isset($parts[1]) && !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? $parts[0] : null;
        // The configuration only allows "table" and "schema.table".
        if ('' === $table || '' === $schema) {
            throw new \LogicException(sprintf('Invalid outbox table name "%s".', $this->tableName));
        }

        // The queries find the table along the search path, DBAL would only look into its first schema.
        if (null === $schema && $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $schema = $this->connection->fetchOne('SELECT n.nspname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.oid = to_regclass(?)', [$table]);
            if (!is_string($schema) || '' === $schema) {
                throw TableDoesNotExist::new($this->tableName);
            }
        }

        // introspectTableByUnquotedName() exists since DBAL 4.3, where introspectTable() is deprecated.
        if (method_exists($schemaManager, 'introspectTableByUnquotedName')) { // @phpstan-ignore function.alreadyNarrowedType
            return $schemaManager->introspectTableByUnquotedName($table, $schema);
        }

        $name = null === $schema ? $table : $schema.'.'.$table;
        try {
            return $schemaManager->introspectTable($name); // @phpstan-ignore method.deprecated
        } catch (TableDoesNotExist $exception) {
            // Before DBAL 4.3 the name is not folded like the database folds unquoted names
            // (PostgreSQL stores "OutboxMessages" as "outboxmessages").
            if (strtolower($name) === $name) {
                throw $exception;
            }

            return $schemaManager->introspectTable(strtolower($name)); // @phpstan-ignore method.deprecated
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
            $table = $this->inDatabaseOfTable(fn (): Table => $this->introspectTable($this->connection->createSchemaManager()));
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

        foreach (self::INDEXES as $suffix => $columns) {
            $table->addIndex($columns, self::indexName($tableName, $suffix));
        }
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
                // Attempts so far, counted when an attempt starts; the relay gives up after "somework_cqrs.outbox.max_attempts".
                'attempts' => $table->addColumn('attempts', Types::INTEGER)->setNotnull(true)->setDefault(0),
                // Earliest time of the next attempt (NULL: never attempted, or requeued).
                'available_at' => $table->addColumn('available_at', Types::DATETIME_IMMUTABLE)->setNotnull(false),
                // When the relay gave up on the message.
                'failed_at' => $table->addColumn('failed_at', Types::DATETIME_IMMUTABLE)->setNotnull(false),
                'last_error' => $table->addColumn('last_error', Types::TEXT)->setNotnull(false),
                default => throw new \LogicException(sprintf('Unknown outbox column "%s".', $column)),
            };
        }
    }

    /**
     * "idx_<table>_<suffix>", falling back to a hashed name when it would exceed the 63-character
     * identifier limit of PostgreSQL/MySQL.
     */
    private static function indexName(string $tableName, string $suffix): string
    {
        $name = 'idx_'.str_replace('.', '_', $tableName).'_'.$suffix;

        return strlen($name) <= 63 ? $name : 'idx_'.substr(sha1($tableName), 0, 16).'_'.$suffix;
    }
}
