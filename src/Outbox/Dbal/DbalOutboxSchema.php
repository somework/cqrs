<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Exception\TableDoesNotExist;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use SomeWork\CqrsBundle\Outbox\SetupLockLeftBehind;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function class_exists;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function method_exists;
use function microtime;
use function preg_replace;
use function random_int;
use function sha1;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function usleep;

use const ARRAY_FILTER_USE_KEY;

/**
 * The outbox table of DbalOutboxStorage: its definition, what an existing table lacks, and the
 * changes that bring it up to date (the setup command and the automatic setup).
 *
 * Changes wait for other setups under a database lock and never queue behind the transactions on
 * the table: writes would queue behind the change.
 *
 * @internal
 */
final class DbalOutboxSchema
{
    /** Columns added in 0.5.0: tables created by earlier versions lack them. */
    public const COLUMNS_SINCE_0_4 = ['attempts', 'available_at', 'failed_at', 'last_error', 'claim_token', 'claimed_at', 'signature'];

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

    /** Seconds after which a process looks at the table again (e.g. whether the setup command changed it meanwhile). */
    private const RECHECK_SECONDS = 60;

    /** The same for the index of the relay while it is missing: the setup command drops the one of 0.4 that the relay then uses. */
    private const INDEX_RECHECK_SECONDS = 10;

    /** Seconds a process that finds the table unusable (e.g. the relay) waits for another setup to finish. */
    private const AUTO_SETUP_LOCK_TIMEOUT = 30;

    /**
     * What the first use found left for the setup command, for pendingChanges(); false when unknown.
     *
     * @var array{create: bool, columns: list<string>, indexes: array<string, non-empty-list<string>>, invalid: list<string>, building: list<string>, legacy: string|null}|false|null
     */
    private array|false|null $knownPlan = false;

    /** When $knownPlan was found (microtime). */
    private float $knownPlanAt = 0.0;

    /** Whether the relay's index exists and is usable, null until checked. */
    private ?bool $pendingIndex = null;

    /** When $pendingIndex was checked (microtime). */
    private float $pendingIndexCheckedAt = 0.0;

    /** @var (\Closure(): void)|null See setup() */
    private ?\Closure $onWait = null;

    /** What the last taken setup lock returned (on PostgreSQL the server process that holds it). */
    private int $lockHolder = 0;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName,
    ) {
    }

    /**
     * Creates the outbox table, or brings an existing one up to date: adds the columns and the
     * index it lacks, rebuilds an index an interrupted build left invalid, and drops the index of
     * 0.4. It waits up to 10 minutes for another setup to finish.
     *
     * @param (\Closure(): void)|null $onWait Called once when another process is setting up the table,
     *                                        before waiting for it (e.g. to say so)
     *
     * @throws \LogicException   when the table must be changed inside an open transaction, or when
     *                           the database rejects the table name
     * @throws \RuntimeException when another process keeps the table locked, or builds its index
     */
    public function setup(?\Closure $onWait = null): void
    {
        $this->onWait = $onWait;
        try {
            $this->guard(fn () => $this->prepare(true));
        } finally {
            $this->onWait = null;
        }
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
        if ($this->cannotInspect()) {
            return [];
        }

        // The first use of this process just looked (e.g. the relay's): no need to look again.
        [$plan, $this->knownPlan] = [$this->knownPlan, false];
        if (false === $plan || microtime(true) - $this->knownPlanAt > self::RECHECK_SECONDS) {
            $plan = $this->guard(fn (): ?array => $this->inDatabaseOfTable(fn (): ?array => $this->plan()));
        }
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
     * @param bool $columns  Whether the caller needs the columns of this version (storing does not)
     *
     * @return bool Whether the table has the columns of this version
     */
    public function prepare(bool $explicit, bool $columns = true): bool
    {
        $complete = $this->inDatabaseOfTable(function () use ($explicit, $columns): bool {
            $plan = $this->plan();
            [$this->knownPlan, $this->knownPlanAt] = [$plan, microtime(true)];

            if (null === $plan) {
                return true;
            }

            if ($explicit) {
                // Say so right away, instead of waiting for the lock of a setup that was killed during the build.
                $this->assertNotBuilding($plan);
                $this->whileLocked(fn () => $this->upgrade(), self::SETUP_LOCK_TIMEOUT);
                [$this->knownPlan, $this->pendingIndex] = [false, null];
            } elseif ($plan['create'] || ($columns && [] !== $plan['columns'])) {
                $this->makeUsable($plan);
                // The table and the columns exist now; the indexes are as they were.
                $left = ['columns' => []] + $plan;
                $this->knownPlan = $plan['create'] || ([] === $left['indexes'] && null === $left['legacy']) ? null : $left;
            } elseif ([] !== $plan['columns']) {
                return false;
            }

            return true;
        });

        // Schema tools quote reserved words, plain queries do not: fail here, not on the first message.
        $this->connection->createQueryBuilder()->select('id')->from($this->tableName)->where('1 = 0')->executeQuery()->free();

        return $complete;
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
            'columns' => array_values(array_filter(self::COLUMNS_SINCE_0_4, static fn (string $column): bool => !$table->hasColumn($column))),
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
        // Another process (e.g. the setup command) may do it while this one waits for the lock.
        $stillNeeded = function (): bool {
            $plan = $this->plan();

            return null !== $plan && ($plan['create'] || [] !== $plan['columns']);
        };

        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            if (!$plan['create'] && $this->oldTransactionOnMySql()) {
                throw new \RuntimeException(sprintf('The outbox table "%s" lacks the columns of this version and is not changed while a transaction of the database server has been open for more than %d second(s): MySQL does not tell which tables it holds, and the writes to the table would wait behind the change. Run "bin/console somework:cqrs:outbox:setup".', $this->tableName, self::AUTO_DDL_LOCK_TIMEOUT));
            }
            $this->whileLocked(fn () => $this->withLockTimeout(fn () => $this->addMissingColumns(), self::AUTO_DDL_LOCK_TIMEOUT), self::AUTO_SETUP_LOCK_TIMEOUT, $stillNeeded);

            return;
        }

        // One transaction for the lock, the lock timeout and the change: none of them outlives it,
        // also not behind a pooler in transaction mode (PgBouncer) that hands the connection on.
        $this->withLockTimeout(function () use ($stillNeeded): void {
            $locked = $this->pollLock('SELECT pg_try_advisory_xact_lock(hashtext(?))', self::AUTO_SETUP_LOCK_TIMEOUT, false, $stillNeeded);
            if (false === $locked) {
                throw $this->setupRunsElsewhere(self::AUTO_SETUP_LOCK_TIMEOUT);
            }
            if (true === $locked) {
                $this->addMissingColumns();
            }
        }, self::AUTO_DDL_LOCK_TIMEOUT);
    }

    /**
     * Whether a transaction has been open for longer than the automatic setup waits: the ALTER
     * would wait for it if it read the table, and hold up the writes behind it meanwhile. MySQL does
     * not tell which tables a transaction holds, so any transaction of the server counts; without
     * the PROCESS privilege nothing is known.
     */
    private function oldTransactionOnMySql(): bool
    {
        // MariaDB changes the table with NOWAIT instead (see addMissingColumns()).
        $platform = $this->connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform || $platform instanceof MariaDBPlatform) {
            return false;
        }

        try {
            // Read NOW() in the time zone that trx_started is shown in (the one of the server).
            $zone = (string) $this->connection->fetchOne('SELECT @@session.time_zone');
            $this->connection->executeStatement("SET time_zone = 'SYSTEM'");
            try {
                return false !== $this->connection->fetchOne(
                    'SELECT 1 FROM information_schema.innodb_trx WHERE trx_mysql_thread_id NOT IN (0, CONNECTION_ID()) AND trx_started < NOW() - INTERVAL ? SECOND LIMIT 1',
                    [self::AUTO_DDL_LOCK_TIMEOUT],
                );
            } finally {
                $this->connection->executeStatement('SET time_zone = '.$this->connection->quote($zone));
            }
        } catch (DbalException) {
            return false;
        }
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
        $diff = $schemaManager->createComparator()->compareTables($current, $upgraded);
        $platform = $this->connection->getDatabasePlatform();

        // The change must not queue behind a transaction on the table: the writes would queue behind it.
        if ($platform instanceof PostgreSQLPlatform) {
            $this->lockWithoutQueueing(self::AUTO_DDL_LOCK_TIMEOUT);
        } elseif ($platform instanceof AbstractMySQLPlatform) {
            // Only a change that takes no time (not a rebuild of a big table, e.g. with ROW_FORMAT=COMPRESSED).
            $statements = array_map(static fn (string $sql): string => str_starts_with($sql, 'ALTER TABLE') ? $sql.', ALGORITHM=INSTANT' : $sql, $platform->getAlterTableSQL($diff));
            try {
                if ($platform instanceof MariaDBPlatform) {
                    $this->alterWithoutQueueing($statements, self::AUTO_DDL_LOCK_TIMEOUT);
                } else {
                    foreach ($statements as $sql) {
                        $this->connection->executeStatement($sql);
                    }
                }
            } catch (DriverException $exception) {
                if ('0A000' !== $exception->getSQLState()) {
                    throw $exception;
                }

                throw new \RuntimeException(sprintf('The outbox table "%s" lacks the columns of this version, which this database cannot add without rebuilding the table. Run "bin/console somework:cqrs:outbox:setup".', $this->tableName), 0, $exception);
            }

            return;
        }

        $schemaManager->alterTable($diff);
    }

    /**
     * Takes the lock of the table for the rest of the transaction (PostgreSQL) only while nobody
     * holds it: a waiting lock request would hold up every later write of the table. Autovacuum
     * gives way to a waiting request after deadlock_timeout, a client does not.
     */
    private function lockWithoutQueueing(int $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while (true) {
            $this->connection->createSavepoint('somework_cqrs_outbox_lock');
            try {
                $this->connection->executeStatement(sprintf('LOCK TABLE %s IN ACCESS EXCLUSIVE MODE NOWAIT', $this->tableName));
                $this->connection->releaseSavepoint('somework_cqrs_outbox_lock');

                return;
            } catch (DriverException $exception) {
                if ('55P03' !== $exception->getSQLState()) {
                    throw $exception;
                }
                $this->connection->rollbackSavepoint('somework_cqrs_outbox_lock');
            }

            if (microtime(true) >= $deadline) {
                break;
            }
            usleep(50_000);
        }

        // Only an autovacuum that gives way (after deadlock_timeout; one that prevents a wraparound
        // does not)? Other sessions count as clients when their details are hidden (without
        // pg_read_all_stats): the upgrade is then left to the setup command.
        $clients = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid WHERE l.relation = to_regclass(?) AND l.granted AND l.pid <> pg_backend_pid() AND (a.backend_type IS DISTINCT FROM 'autovacuum worker' OR a.query LIKE '%(to prevent wraparound)%')",
            [$this->tableName],
        );
        if (0 !== $clients) {
            throw $this->tableLocked($seconds);
        }

        // The writes of the table wait behind this request until autovacuum is cancelled.
        $this->connection->executeStatement("SELECT set_config('lock_timeout', (EXTRACT(EPOCH FROM current_setting('deadlock_timeout')::interval) * 1000 + 500)::int || 'ms', true)");
        $this->connection->executeStatement(sprintf('LOCK TABLE %s IN ACCESS EXCLUSIVE MODE', $this->tableName));
    }

    /**
     * Runs the ALTER TABLE statements with NOWAIT (MariaDB) until they get the table, for at most
     * $seconds: a waiting ALTER would hold up every later write of the table.
     *
     * @param list<string> $statements
     */
    private function alterWithoutQueueing(array $statements, int $seconds): void
    {
        foreach ($statements as $sql) {
            $sql = (string) preg_replace('/^ALTER TABLE (\S+) /', 'ALTER TABLE $1 NOWAIT ', $sql);
            $deadline = microtime(true) + $seconds;

            while (true) {
                try {
                    $this->connection->executeStatement($sql);

                    break;
                } catch (LockWaitTimeoutException $exception) {
                    if (microtime(true) >= $deadline) {
                        throw $this->tableLocked($seconds, $exception);
                    }
                    usleep(50_000);
                }
            }
        }
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

        $this->assertNotBuilding($plan);

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
     * @param array{indexes: array<string, non-empty-list<string>>, building: list<string>} $plan
     */
    private function assertNotBuilding(array $plan): void
    {
        $building = array_values(array_filter(array_keys($plan['indexes']), fn (string $suffix): bool => in_array(strtolower(self::indexName($this->tableName, $suffix)), $plan['building'], true)));
        if ([] !== $building) {
            // e.g. a migration: dropping its index would fail once it is done, and throw its work away.
            throw new \RuntimeException(sprintf('Another process is building the index "%s" of the outbox table "%s". Run "bin/console somework:cqrs:outbox:setup" again once it has finished.', self::indexName($this->tableName, $building[0]), $this->tableName));
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

            throw $this->tableLocked($seconds, $exception);
        }
    }

    private function tableLocked(int $seconds, ?\Throwable $previous = null): \RuntimeException
    {
        $holder = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'another session (a transaction, or an autovacuum that does not give way)' : 'another session (a transaction)';

        return new \RuntimeException(sprintf('The outbox table "%s" could not be changed: %s kept it locked for more than %d second(s). Run "bin/console somework:cqrs:outbox:setup" when the table is less busy.', $this->tableName, $holder, $seconds), 0, $previous);
    }

    /**
     * Holds a database lock while $setup runs, so processes that start at the same time do not
     * change the table concurrently. It is a session lock: run the setup command over a direct
     * connection, not through a pooler in transaction mode (PgBouncer). Inside a transaction, where
     * the table cannot be changed anyway, and on SQLite no lock is taken.
     *
     * @param \Closure(): void        $setup
     * @param int                     $timeout     Seconds to wait for another setup to finish
     * @param (\Closure(): bool)|null $stillNeeded Whether $setup still has to run, checked while waiting
     */
    private function whileLocked(\Closure $setup, int $timeout, ?\Closure $stillNeeded = null): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($this->connection->isTransactionActive() || !($platform instanceof PostgreSQLPlatform || $platform instanceof AbstractMySQLPlatform)) {
            $setup();

            return;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            $this->assertDirectConnection();
        }

        // Polled, not waited for: on PostgreSQL, CREATE INDEX CONCURRENTLY waits for every running
        // statement, so a process blocked in pg_advisory_lock() would deadlock with the one holding
        // the lock. On MySQL, in short waits, so that a second signal stops the process. The
        // PostgreSQL lock returns the server process that holds it.
        $locked = $platform instanceof PostgreSQLPlatform
            ? $this->pollLock('SELECT CASE WHEN pg_try_advisory_lock(hashtext(?)) THEN pg_backend_pid() ELSE 0 END', $timeout, false, $stillNeeded)
            : $this->pollLock('SELECT GET_LOCK(?, 1)', $timeout, true, $stillNeeded);
        if (false === $locked) {
            throw $this->setupRunsElsewhere($timeout);
        }
        if (null === $locked) {
            return;
        }

        $failure = null;
        try {
            if ($platform instanceof PostgreSQLPlatform && (int) $this->connection->fetchOne('SELECT pg_backend_pid()') !== $this->lockHolder) {
                throw $this->pooler();
            }
            $setup();
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            // A lost connection (e.g. its server process was terminated) took its lock with it.
            try {
                $released = $failure instanceof ConnectionException || $this->releaseSessionLock();
            } catch (DbalException $releaseFailure) {
                // e.g. the connection is gone: the lock went with its session; the first failure matters.
                throw $failure ?? $releaseFailure;
            }
            if (!$released) {
                // A pooler handed the statements around: the lock stays with the server connection that took it.
                throw new SetupLockLeftBehind(sprintf('The outbox table "%s" %s; it ran through a pooler in transaction mode (e.g. PgBouncer): the setup lock (and possibly a statement_timeout of 0) stays with another server connection until it closes (e.g. RECONNECT in the admin console of PgBouncer), and blocks the next setup. Run "bin/console somework:cqrs:outbox:setup" over a direct database connection.', $this->tableName, null === $failure ? 'is set up' : sprintf('was not set up (%s)', $failure->getMessage())), 0, $failure);
            }
        } else {
            $this->connection->executeQuery('SELECT RELEASE_LOCK(?)', [$this->setupLockName()])->free();
        }

        if (null !== $failure) {
            throw $failure;
        }
    }

    /**
     * Releases the PostgreSQL session lock on the server process that holds it; behind a pooler, a
     * statement may run on another one, so it tries a few times.
     */
    private function releaseSessionLock(): bool
    {
        for ($try = 0; $try < 100; ++$try) {
            // With its server process, the lock is gone too (e.g. the connection was lost and DBAL reconnected).
            $released = $this->connection->fetchOne(
                'SELECT CASE WHEN pg_backend_pid() = ? THEN pg_advisory_unlock(hashtext(?)) WHEN NOT EXISTS (SELECT 1 FROM pg_stat_activity WHERE pid = ?) THEN true END',
                [$this->lockHolder, $this->setupLockName(), $this->lockHolder],
            );
            if (null !== $released && false !== $released) {
                return (bool) $released;
            }
            usleep(20_000);
        }

        return false;
    }

    private function pooler(): \RuntimeException
    {
        return new \RuntimeException(sprintf('The outbox table "%s" is not set up through a pooler in transaction mode (e.g. PgBouncer), which would hand the setup lock to other clients. Run "bin/console somework:cqrs:outbox:setup" over a direct database connection.', $this->tableName));
    }

    /**
     * A pooler in transaction mode (PgBouncer) hands every statement to any server connection: the
     * session lock and settings of the setup would stay with other clients. Not every pooler is
     * noticed (e.g. without other traffic, the same server connection serves every statement).
     */
    private function assertDirectConnection(): void
    {
        if ($this->connection->fetchOne('SELECT pg_backend_pid()') !== $this->connection->fetchOne('SELECT pg_backend_pid()')) {
            throw $this->pooler();
        }
    }

    /**
     * Runs $sql, which returns true, 1 or the holding server process once it got the setup lock,
     * until $timeout seconds passed.
     *
     * @param bool                    $waits       Whether $sql waits for the lock itself
     * @param (\Closure(): bool)|null $stillNeeded Checked after every try: false ends the wait
     *
     * @return bool|null True when locked, false after the timeout, null when no longer needed
     */
    private function pollLock(string $sql, int $timeout, bool $waits, ?\Closure $stillNeeded = null): ?bool
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            $locked = (int) $this->connection->fetchOne($sql, [$this->setupLockName()]);
            if ($locked > 0) {
                $this->lockHolder = $locked;

                return true;
            }
            if (null !== $stillNeeded && !$stillNeeded()) {
                return null;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            if (null !== $this->onWait) {
                [$onWait, $this->onWait] = [$this->onWait, null];
                $onWait();
            }
            if (!$waits) {
                // Shorter inside a transaction (the automatic setup), which idle_in_transaction_session_timeout may end.
                $inTransaction = $this->connection->isTransactionActive();
                usleep(random_int($inTransaction ? 20_000 : 50_000, $inTransaction ? 80_000 : 250_000));
            }
        }
    }

    private function setupLockName(): string
    {
        // PostgreSQL's advisory locks belong to the database, MySQL's named locks to the server.
        $parts = $this->tableParts();
        $name = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? (isset($parts[1]) ? $parts[0].'.'.$parts[1] : $this->connection->getDatabase().'.'.$parts[0])
            : $this->tableName;

        return 'somework_cqrs_outbox_setup_'.substr(sha1(strtolower($name)), 0, 16);
    }

    /**
     * DBAL cannot look into a "database.table" of MySQL without a database selected.
     */
    public function cannotInspect(): bool
    {
        return isset($this->tableParts()[1]) && $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform && null === $this->connection->getDatabase();
    }

    /**
     * @return array{0: string, 1?: string} "schema.table" split, or the unqualified name
     */
    private function tableParts(): array
    {
        return explode('.', $this->tableName, 2);
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
     * Whether the relay's index exists and is usable; checked again every 10 seconds while it is not
     * (the setup command may build it meanwhile). When the check fails, the index is assumed.
     */
    public function hasPendingIndex(): bool
    {
        if (true === $this->pendingIndex || (null !== $this->pendingIndex && microtime(true) - $this->pendingIndexCheckedAt < self::INDEX_RECHECK_SECONDS)) {
            return $this->pendingIndex;
        }

        $name = self::indexName($this->tableName, 'pending');
        $parts = $this->tableParts();
        $platform = $this->connection->getDatabasePlatform();

        try {
            $found = match (true) {
                $platform instanceof PostgreSQLPlatform => $this->connection->fetchOne('SELECT i.indisvalid FROM pg_index i WHERE i.indexrelid = to_regclass(?) AND i.indrelid = to_regclass(?)', [$this->qualifiedIndexName($name), $this->tableName]),
                $platform instanceof AbstractMySQLPlatform => $this->connection->fetchOne(
                    'SELECT 1 FROM information_schema.statistics WHERE table_schema = COALESCE(?, DATABASE()) AND table_name = ? AND index_name = ? LIMIT 1',
                    [isset($parts[1]) ? $parts[0] : null, $parts[1] ?? $parts[0], $name],
                ),
                default => $this->connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = ?", [$name]),
            };
            $this->pendingIndex = true === $found || 1 === (int) $found;
        } catch (DbalException) {
            $this->pendingIndex = true;
        }
        $this->pendingIndexCheckedAt = microtime(true);

        return $this->pendingIndex;
    }

    /**
     * @param list<string> $columns
     */
    private function createIndexConcurrently(string $name, array $columns): void
    {
        // A statement timeout of the role would cancel a long build on every run. The setting must
        // stay with the server process that holds the setup lock (not with a pooled one).
        $previousTimeout = (string) $this->connection->fetchOne('SHOW statement_timeout');
        if ((int) $this->connection->fetchOne("SELECT pg_backend_pid() FROM (SELECT set_config('statement_timeout', '0', false)) s") !== $this->lockHolder) {
            throw $this->pooler();
        }

        try {
            $this->connection->executeStatement(sprintf('CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)', $name, $this->tableName, implode(', ', $columns)));
        } catch (DbalException $exception) {
            // A failed concurrent build leaves an invalid index behind, which the next setup would take for done.
            $this->connection->executeStatement(sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', $this->qualifiedIndexName($name)));

            throw $exception;
        } finally {
            try {
                $this->connection->executeStatement(sprintf('SET statement_timeout = %s', $this->connection->quote($previousTimeout)));
            } catch (DbalException) {
                // e.g. the connection is lost (its settings with it): the failure of the build matters.
            }
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

    /**
     * Turns the errors of a missing, outdated or unusable table into instructions.
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public function guard(\Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (TableNotFoundException $exception) {
            throw new \LogicException(sprintf('The outbox table "%s" does not exist. Create it with "bin/console somework:cqrs:outbox:setup" or a Doctrine migration; it is never created inside an open transaction.', $this->tableName), 0, $exception);
        } catch (SyntaxErrorException $exception) {
            throw new \LogicException(sprintf('The database rejected a query on the outbox table "%s"; the name is probably a reserved word of this database. Choose another "somework_cqrs.outbox.table_name".', $this->tableName), 0, $exception);
        } catch (DriverException $exception) {
            // Not every driver reports an unknown column as InvalidFieldNameException (SQLite does not).
            if (!$exception instanceof ConnectionException && ($exception instanceof InvalidFieldNameException || $this->lacksColumns())) {
                throw new \LogicException(sprintf('The outbox table "%s" lacks columns this version of the bundle needs (%s). Upgrade it with "bin/console somework:cqrs:outbox:setup" or a Doctrine migration.', $this->tableName, implode(', ', self::COLUMNS_SINCE_0_4)), 0, $exception);
            }

            throw $exception;
        }
    }

    private function lacksColumns(): bool
    {
        try {
            $table = $this->inDatabaseOfTable(fn (): Table => $this->introspectTable($this->connection->createSchemaManager()));
        } catch (\Throwable) {
            return false;
        }

        foreach (self::COLUMNS_SINCE_0_4 as $column) {
            if (!$table->hasColumn($column)) {
                return true;
            }
        }

        return false;
    }

    private static function buildTableDefinition(string $tableName): Table
    {
        $table = new Table($tableName);

        self::configureTable($table, $tableName);

        return $table;
    }

    public static function configureTable(Table $table, string $tableName): void
    {
        self::addColumns($table, ['id', 'body', 'headers', 'transport_name', 'created_at', 'published_at', ...self::COLUMNS_SINCE_0_4]);

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
                // The relay run that claimed the message for an attempt it has not finished, and when.
                'claim_token' => $table->addColumn('claim_token', Types::STRING)->setLength(32)->setNotnull(false),
                'claimed_at' => $table->addColumn('claimed_at', Types::DATETIME_IMMUTABLE)->setNotnull(false),
                // "v1:" and the base64url HMAC-SHA256 of the id, body and headers (outbox.signing).
                'signature' => $table->addColumn('signature', Types::STRING)->setLength(64)->setNotnull(false),
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
