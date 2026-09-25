<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\Dbal\DbalOutboxSchema;

use function array_column;
use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function get_debug_type;
use function in_array;
use function is_string;
use function min;
use function random_int;
use function sprintf;
use function str_contains;
use function strtolower;

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
    private const PURGE_BATCH_SIZE = 1000;

    /**
     * Bytes of bodies and headers one fetch reads at most (it always reads one row): a batch
     * of large messages must not exhaust the memory of the relay before any of them is claimed.
     */
    private const FETCH_BUDGET = 8 * 1024 * 1024;

    /** Rotates the order of transports whose next rows tie. */
    private int $ties;

    /** Process-local cache of the "table is up to date" check. */
    private bool $setupDone = false;

    /** Process-local cache of the "table exists" check (it may still lack the columns of this version). */
    private bool $tableExists = false;

    private readonly DbalOutboxSchema $schema;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName = 'somework_cqrs_outbox',
        private readonly bool $autoSetup = true,
    ) {
        $this->schema = new DbalOutboxSchema($connection, $tableName);
        $this->ties = random_int(0, 1 << 20);
    }

    public function store(OutboxMessage $message): void
    {
        // Only the table: an upgrade could wait for the transactions on it, writes must not.
        $this->ensureTableExists(false);

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
        // Without that index (until the setup command builds it), each of those queries would read
        // every pending row: one query along the index of 0.4 instead, in the order rows were stored.
        $rows = !$this->schema->hasPendingIndex()
            ? $this->dueRowsInStoredOrder($limit, $excludedTransports)
            : $this->dueRows($limit, $this->transportsExcept($excludedTransports));

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
        // Purging does not need the columns of this version (e.g. before the upgrade of a big 0.4 table).
        $this->ensureTableExists(false);

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
     * The unpublished messages and when the oldest was stored, read with the columns of 0.4 only
     * (for monitoring a table that still waits for its upgrade).
     *
     * @internal
     *
     * @return array{count: int, oldest: DateTimeImmutable|null}
     */
    public function unpublishedBacklog(): array
    {
        $row = $this->guard(fn (): array|false => $this->connection->createQueryBuilder()
            ->select('COUNT(*) AS unpublished', 'MIN(created_at) AS since')
            ->from($this->tableName)
            ->where('published_at IS NULL')
            ->executeQuery()
            ->fetchAssociative());

        return [
            'count' => false === $row ? 0 : (int) $row['unpublished'],
            'oldest' => false === $row || null === $row['since'] ? null : self::readUtc($row['since'], $this->connection->getDatabasePlatform()),
        ];
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
     * @param list<string> $ids           The messages to requeue; all given-up messages when empty
     * @param string|null  $transportName A transport to send them to instead of the stored one (e.g. after a renamed transport)
     *
     * @return int The number of requeued messages
     */
    public function requeueFailed(array $ids = [], ?string $transportName = null): int
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

        if (null !== $transportName) {
            $query->set('transport_name', ':transport_name')->setParameter('transport_name', $transportName);
        }

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
     * @param (\Closure(): void)|null $onWait Called once when another process is setting up the table,
     *                                        before waiting for it (e.g. to say so)
     *
     * @throws \LogicException   when the table must be changed inside an open transaction, or when
     *                           the database rejects the table name
     * @throws \RuntimeException when another process keeps the table locked, or builds its index
     */
    public function setup(?\Closure $onWait = null): void
    {
        $this->schema->setup($onWait);
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
        return $this->schema->pendingChanges();
    }

    /**
     * Adds the outbox table definition to an existing Schema object.
     *
     * Useful for Doctrine schema listeners or migration generation.
     */
    public static function addTableToSchema(Schema $schema, string $tableName = 'somework_cqrs_outbox'): Table
    {
        $table = $schema->createTable($tableName);

        DbalOutboxSchema::configureTable($table, $tableName);

        return $table;
    }

    /**
     * Adds the table as $name, with the columns and indexes of the configured $tableName: MySQL lists
     * a "database.table" of the connection's own database as "table".
     *
     * @internal
     */
    public static function addTableToSchemaAs(Schema $schema, string $name, string $tableName): Table
    {
        $table = $schema->createTable($name);

        DbalOutboxSchema::configureTable($table, $tableName);

        return $table;
    }

    /**
     * The due rows of all but the excluded transports, in the order they were stored.
     *
     * @param list<string|null> $excludedTransports
     *
     * @return list<array<string, mixed>>
     */
    private function dueRowsInStoredOrder(int $limit, array $excludedTransports): array
    {
        $query = $this->pending()
            ->andWhere('available_at IS NULL OR available_at <= :now')
            ->setParameter('now', self::now(), Types::DATETIME_IMMUTABLE)
            ->setMaxResults($limit);

        $names = array_values(array_filter($excludedTransports, static fn (?string $name): bool => null !== $name));
        if (in_array(null, $excludedTransports, true)) {
            $query->andWhere('transport_name IS NOT NULL');
        }
        if ([] !== $names) {
            $query->andWhere(in_array(null, $excludedTransports, true) ? 'transport_name NOT IN (:excluded)' : 'transport_name IS NULL OR transport_name NOT IN (:excluded)')
                ->setParameter('excluded', $names, ArrayParameterType::STRING);
        }

        $ids = $this->guard(fn (): array => $this->ordered($query->select('id'), ['published_at'], ['created_at', 'id'])->executeQuery()->fetchFirstColumn());

        return $this->readDue(array_map(strval(...), $ids));
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

        return $this->readDue($ids);
    }

    /**
     * Reads the given rows in full, in the given order, as long as their bodies and headers fit
     * into the budget of a fetch (the first row is always read); the others wait for the next
     * fetch. A row that is no longer due (published, given up or claimed by another relay in the
     * meantime) is left out.
     *
     * @param list<string> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function readDue(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $platform = $this->connection->getDatabasePlatform();
        // In bytes where the platform tells (PostgreSQL then needs no decompression of the value).
        $length = static fn (string $column): string => match (true) {
            $platform instanceof PostgreSQLPlatform => sprintf('OCTET_LENGTH(%s)', $column),
            $platform instanceof AbstractMySQLPlatform => sprintf('LENGTH(%s)', $column),
            default => $platform->getLengthExpression($column),
        };
        $due = static fn (QueryBuilder $query, array $ids): QueryBuilder => $query
            ->andWhere('id IN (:ids)')
            ->andWhere('available_at IS NULL OR available_at <= :now')
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->setParameter('now', self::now(), Types::DATETIME_IMMUTABLE);

        $sizes = [];
        $query = $due($this->pending()->select('id', sprintf('%s + %s AS size', $length('body'), $length('headers'))), $ids);
        foreach ($this->guard(static fn (): array => $query->executeQuery()->fetchAllAssociative()) as $row) {
            $sizes[strtolower((string) $row['id'])] = (int) $row['size'];
        }

        $chosen = [];
        $total = 0;
        foreach ($ids as $id) {
            $size = $sizes[strtolower($id)] ?? null;
            if (null === $size) {
                continue;
            }
            if ([] !== $chosen && $total + $size > self::FETCH_BUDGET) {
                break;
            }
            $chosen[] = $id;
            $total += $size;
        }

        if ([] === $chosen) {
            return [];
        }

        $query = $due($this->pending(), $chosen);
        $rows = [];
        foreach ($this->guard(static fn (): array => $query->executeQuery()->fetchAllAssociative()) as $row) {
            $rows[strtolower((string) $row['id'])] = $row;
        }

        $read = [];
        foreach ($chosen as $id) {
            if (isset($rows[strtolower($id)])) {
                $read[] = $rows[strtolower($id)];
            }
        }

        return $read;
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

    /**
     * @param bool $columns Whether the caller needs the columns of this version, or only the table
     */
    private function ensureTableExists(bool $columns = true): void
    {
        // Inside a transaction the table cannot be changed; a missing table or column then fails the query itself.
        // (The existence check is also unreliable there: schema filters and qualified names hide tables.)
        if ($this->setupDone || ($this->tableExists && !$columns) || !$this->autoSetup || $this->connection->isTransactionActive()) {
            return;
        }

        // The queries themselves report a missing table or column.
        if ($this->schema->cannotInspect()) {
            $this->setupDone = true;

            return;
        }

        $this->setupDone = $this->guard(fn (): bool => $this->schema->prepare(false, $columns));
        $this->tableExists = true;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function guard(\Closure $operation): mixed
    {
        return $this->schema->guard($operation);
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
}
