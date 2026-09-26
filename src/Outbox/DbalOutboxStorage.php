<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use SomeWork\CqrsBundle\Contract\Outbox\FailedOutboxMessages;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxMonitoring;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxSchema;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use SomeWork\CqrsBundle\Contract\Outbox\TransactionalOutbox;
use SomeWork\CqrsBundle\Outbox\Dbal\DbalOutboxSchema;
use SomeWork\CqrsBundle\Outbox\Relay\RelayUnitOfWork;

use function array_chunk;
use function array_column;
use function array_fill_keys;
use function array_filter;
use function array_flip;
use function array_map;
use function array_slice;
use function array_sum;
use function array_values;
use function ceil;
use function count;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function microtime;
use function min;
use function preg_replace;
use function random_int;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function usleep;
use function usort;

use const JSON_THROW_ON_ERROR;

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
final class DbalOutboxStorage implements OutboxStorage, OutboxSchema, FailedOutboxMessages, OutboxMonitoring, TransactionalOutbox, RelayUnitOfWork
{
    private const PURGE_BATCH_SIZE = 1000;

    /**
     * Bytes of bodies and headers one fetch reads at most (it always reads one row): a batch
     * of large messages must not exhaust the memory of the relay before any of them is claimed.
     */
    private const FETCH_BUDGET = 8 * 1024 * 1024;

    /** Transports whose due rows one statement reads (with UNION ALL). */
    private const TRANSPORTS_PER_STATEMENT = 50;

    /** Seconds a relay reuses the list of transports that have pending rows. */
    private const TRANSPORT_LIST_SECONDS = 10;

    /** Rotates the order of transports whose next rows tie. */
    private int $ties;

    /** @var list<string|null>|null The transports that had pending rows, for the next fetches of a relay run */
    private ?array $transports = null;

    private float $transportsListedAt = 0.0;

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
        // Outside a transaction the automatic setup adds missing columns; inside one, a table of
        // 0.4 fails the insert with an error that asks for the setup command.
        $this->ensureTableExists();

        $this->guard(fn () => $this->connection->insert($this->tableName, [
            'id' => $message->id,
            'body' => $message->body,
            'headers' => $message->headers,
            'transport_name' => $message->transportName,
            'created_at' => self::utc($message->createdAt),
            'published_at' => null,
            'signature' => $message->signature,
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
        $this->readFromPrimary();
        $this->ensureTableExists();

        // Transports take turns, so the backlog of one (e.g. after an outage) does not hold up the
        // others; each is queried on its own part of the index, a paused one is not even read.
        // Without that index (until the setup command builds it), each of those queries would read
        // every pending row: one query along the index of 0.4 instead, in the order rows were stored.
        if (!$this->schema->hasPendingIndex()) {
            $rows = $this->dueRowsInStoredOrder($limit, $excludedTransports);
        } else {
            // Listing the transports takes one probe per transport: a relay run reuses the list
            // while its fetches come back full (a short fetch may mean that a transport is new).
            if (null === $this->transports || microtime(true) - $this->transportsListedAt >= self::TRANSPORT_LIST_SECONDS) {
                $this->transports = $this->transportsExcept([]);
                $this->transportsListedAt = microtime(true);
            }
            $transports = array_values(array_filter($this->transports, static fn (?string $transport): bool => !in_array($transport, $excludedTransports, true)));
            $rows = $this->dueRows($limit, $transports);
            if (count($rows) < $limit) {
                $this->transports = null;
            }
        }
        // With auto-commit off, an idle "--watch" would otherwise keep the transaction of its
        // reads open ("idle in transaction"), and with REPEATABLE READ its snapshot, blind to new rows.
        $this->commitImplicitTransaction();

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
                claimedAt: null === $row['claimed_at'] ? null : self::readUtc($row['claimed_at'], $platform),
                availableAt: null === $row['available_at'] ? null : self::readUtc($row['available_at'], $platform),
                signature: null === $row['signature'] ? null : (string) $row['signature'],
            ),
            $rows,
        );
    }

    public function claim(array $messages, array $retryAt, string $token): array
    {
        if ([] === $messages) {
            return [];
        }

        $this->ensureTableExists();

        // One statement per group of messages that were fetched with the same attempts and transport.
        /** @var array<string, non-empty-list<OutboxMessage>> $groups */
        $groups = [];
        foreach ($messages as $message) {
            $groups[$message->attempts.'|'.($message->transportName ?? "\0")][] = $message;
        }

        $now = self::now();
        $complete = true;
        foreach ($groups as $group) {
            $attempts = $group[0]->attempts;
            if (!isset($retryAt[$attempts])) {
                throw new \InvalidArgumentException(sprintf('No retry time was given for messages with %d attempt(s).', $attempts));
            }

            $query = $this->connection->createQueryBuilder()
                ->update($this->tableName)
                ->set('claim_token', ':token')
                ->set('claimed_at', ':claimed_at')
                ->set('available_at', ':available_at')
                // Last: MySQL evaluates the assignments from left to right.
                ->set('attempts', 'attempts + 1')
                ->where('id IN (:ids)')
                ->andWhere('attempts = :attempts')
                ->andWhere('published_at IS NULL')
                ->andWhere('failed_at IS NULL')
                ->setParameter('token', $token)
                ->setParameter('claimed_at', $now, Types::DATETIME_IMMUTABLE)
                ->setParameter('available_at', self::utc($retryAt[$attempts]), Types::DATETIME_IMMUTABLE)
                ->setParameter('ids', array_map(static fn (OutboxMessage $message): string => $message->id, $group), ArrayParameterType::STRING)
                ->setParameter('attempts', $attempts, Types::INTEGER);
            // e.g. "outbox:failed --requeue --transport" moved the message to another transport meanwhile.
            if (null === $group[0]->transportName) {
                $query->andWhere('transport_name IS NULL');
            } else {
                $query->andWhere('transport_name = :transport')->setParameter('transport', $group[0]->transportName);
            }

            // The claim token always changes the row, so the count is exact on MySQL too.
            $claimed = (int) $this->retryOnce(fn (): int|string => $this->guard(static fn (): int|string => $query->executeStatement()));
            $complete = $complete && $claimed === count($group);
        }
        $this->commitImplicitTransaction();

        $ids = array_map(static fn (OutboxMessage $message): string => $message->id, $messages);
        if ($complete) {
            return $ids;
        }

        // Another relay claimed some of them first: read back which ones carry this claim.
        $rows = $this->guard(fn (): array => $this->connection->createQueryBuilder()
            ->select('id', 'attempts')
            ->from($this->tableName)
            ->where('id IN (:ids)')
            ->andWhere('claim_token = :token')
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->setParameter('token', $token)
            ->executeQuery()
            ->fetchAllAssociative());
        $claimed = [];
        foreach ($rows as $row) {
            $claimed[strtolower((string) $row['id'])] = (int) $row['attempts'];
        }

        return array_values(array_map(
            static fn (OutboxMessage $message): string => $message->id,
            array_filter($messages, static fn (OutboxMessage $message): bool => ($claimed[$message->id] ?? null) === $message->attempts + 1),
        ));
    }

    public function renew(array $messages, array $retryAt, string $token): array
    {
        if ([] === $messages) {
            return [];
        }

        $this->ensureTableExists();

        /** @var array<int, non-empty-list<OutboxMessage>> $groups */
        $groups = [];
        foreach ($messages as $message) {
            $groups[$message->attempts][] = $message;
        }

        $now = self::now();
        foreach ($groups as $attempts => $group) {
            if (!isset($retryAt[$attempts])) {
                throw new \InvalidArgumentException(sprintf('No retry time was given for messages with %d attempt(s).', $attempts));
            }
            $query = $this->connection->createQueryBuilder()
                ->update($this->tableName)
                ->set('claimed_at', ':claimed_at')
                ->set('available_at', ':available_at')
                ->where('id IN (:ids)')
                ->andWhere('claim_token = :token')
                ->andWhere('published_at IS NULL')
                ->andWhere('failed_at IS NULL')
                ->setParameter('claimed_at', $now, Types::DATETIME_IMMUTABLE)
                ->setParameter('available_at', self::utc($retryAt[$attempts]), Types::DATETIME_IMMUTABLE)
                ->setParameter('ids', array_map(static fn (OutboxMessage $message): string => $message->id, $group), ArrayParameterType::STRING)
                ->setParameter('token', $token);
            $this->retryOnce(fn (): int|string => $this->guard(static fn (): int|string => $query->executeStatement()));
        }
        $this->commitImplicitTransaction();

        // MySQL counts changed rows only (a renewal within the same second changes nothing): read back.
        $ids = array_map(static fn (OutboxMessage $message): string => $message->id, $messages);
        $held = $this->guard(fn (): array => $this->connection->createQueryBuilder()
            ->select('id')
            ->from($this->tableName)
            ->where('id IN (:ids)')
            ->andWhere('claim_token = :token')
            ->andWhere('published_at IS NULL')
            ->andWhere('failed_at IS NULL')
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->setParameter('token', $token)
            ->executeQuery()
            ->fetchFirstColumn());
        $held = array_flip(array_map(static fn (mixed $id): string => strtolower((string) $id), $held));

        return array_values(array_filter($ids, static fn (string $id): bool => isset($held[$id])));
    }

    public function release(array $messages, string $token): void
    {
        if ([] === $messages) {
            return;
        }

        $this->ensureTableExists();

        foreach ($messages as $message) {
            $this->retryOnce(fn (): int|string => $this->guard(fn (): int|string => $this->connection->createQueryBuilder()
                ->update($this->tableName)
                ->set('claim_token', 'NULL')
                ->set('claimed_at', ':claimed_at')
                ->set('available_at', ':available_at')
                ->set('attempts', 'attempts - 1')
                ->where('id = :id')
                ->andWhere('claim_token = :token')
                ->andWhere('published_at IS NULL')
                ->andWhere('failed_at IS NULL')
                ->setParameter('claimed_at', null === $message->claimedAt ? null : self::utc($message->claimedAt), Types::DATETIME_IMMUTABLE)
                ->setParameter('available_at', null === $message->availableAt ? null : self::utc($message->availableAt), Types::DATETIME_IMMUTABLE)
                ->setParameter('id', $message->id)
                ->setParameter('token', $token)
                ->executeStatement()));
        }
        $this->commitImplicitTransaction();
    }

    public function markPublished(array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $this->ensureTableExists();

        // Idempotent, and a failure makes the relay send these messages again: retry serialization
        // failures and deadlocks (e.g. overlapping relays at SERIALIZABLE isolation) a few times.
        $this->retry(5, fn (): int|string => $this->guard(fn (): int|string => $this->connection->createQueryBuilder()
            ->update($this->tableName)
            ->set('published_at', ':published_at')
            ->set('failed_at', 'NULL')
            ->set('last_error', 'NULL')
            ->set('claim_token', 'NULL')
            ->set('claimed_at', 'NULL')
            ->where('id IN (:ids)')
            ->andWhere('published_at IS NULL')
            ->setParameter('published_at', self::now(), Types::DATETIME_IMMUTABLE)
            ->setParameter('ids', array_map(strtolower(...), $ids), ArrayParameterType::STRING)
            ->executeStatement()));
        $this->commitImplicitTransaction();
    }

    public function recordFailure(string $id, string $token, int $attempts, string $error, ?DateTimeImmutable $retryAt): bool
    {
        $this->ensureTableExists();

        $query = $this->connection->createQueryBuilder()
            ->update($this->tableName)
            ->set('attempts', ':attempts')
            ->set('last_error', ':last_error')
            ->set('available_at', ':available_at')
            ->set('failed_at', ':failed_at')
            ->set('claim_token', 'NULL')
            ->set('claimed_at', 'NULL')
            ->where('id = :id')
            ->andWhere('claim_token = :token')
            ->andWhere('published_at IS NULL')
            ->setParameter('attempts', $attempts, Types::INTEGER)
            ->setParameter('last_error', $error)
            ->setParameter('available_at', null === $retryAt ? null : self::utc($retryAt), Types::DATETIME_IMMUTABLE)
            ->setParameter('failed_at', null === $retryAt ? self::now() : null, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', strtolower($id))
            ->setParameter('token', $token);

        // Clearing the claim token always changes the row, so the count is exact on MySQL too.
        $recorded = 0 !== (int) $this->retryOnce(fn (): int|string => $this->guard(static fn (): int|string => $query->executeStatement()));
        $this->commitImplicitTransaction();

        return $recorded;
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
            $this->commitImplicitTransaction();
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
        $this->readFromPrimary();
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

    public function status(): OutboxStatus
    {
        $this->readFromPrimary();
        // Monitoring only reads: it never changes the table (an upgrade may be running elsewhere).
        // Counts stop at OutboxStatus::COUNT_CAP, and every query reads along an index: the
        // pending rows are restricted to the listed transports, so the conditions on available_at
        // become ranges of the index instead of a filter over every pending row.
        $now = self::now();
        $capped = false;
        $count = function (QueryBuilder $query) use (&$capped): int {
            $counted = (int) $this->capped($query, 'COUNT(*)');
            $capped = $capped || $counted > OutboxStatus::COUNT_CAP;

            return min($counted, OutboxStatus::COUNT_CAP);
        };
        $indexed = $this->schema->hasPendingIndex();
        $transports = $indexed ? $this->transportsExcept([]) : [];
        $pending = function () use ($indexed, $transports): QueryBuilder {
            $query = $this->pending();
            if (!$indexed) {
                return $query;
            }
            $names = array_values(array_filter($transports, static fn (?string $transport): bool => null !== $transport));
            if ([] === $names) {
                return $query->andWhere('transport_name IS NULL');
            }

            return $query->andWhere(in_array(null, $transports, true) ? 'transport_name IN (:transports) OR transport_name IS NULL' : 'transport_name IN (:transports)')
                ->setParameter('transports', $names, ArrayParameterType::STRING);
        };

        $due = $count($pending()->andWhere('available_at IS NULL'))
            + $count($pending()->andWhere('available_at <= :now')->setParameter('now', $now, Types::DATETIME_IMMUTABLE));
        $capped = $capped || $due > OutboxStatus::COUNT_CAP;

        // Rows whose last attempt failed: postponed, and not claimed right now; or claimed again after
        // an attempt that was interrupted (a relay that keeps dying on them).
        $failing = $pending()->andWhere('available_at IS NOT NULL')->andWhere('claim_token IS NULL OR attempts > 1');
        $retrying = $count(clone $failing);
        $oldestRetrying = $this->capped($failing, 'MIN(created_at)');

        $failed = $count($this->connection->createQueryBuilder()->from($this->tableName)->where('published_at IS NULL')->andWhere('failed_at IS NOT NULL'));

        // Claims of attempts that have not finished (published and given-up rows have none).
        $inFlight = $count($this->connection->createQueryBuilder()->from($this->tableName)->where('claimed_at IS NOT NULL')->andWhere('available_at > :now')->setParameter('now', $now, Types::DATETIME_IMMUTABLE));
        // A claim runs out at the retry time of its attempt (available_at).
        $expiredClaim = $this->guard(fn (): mixed => $this->connection->createQueryBuilder()
            ->select('available_at')
            ->from($this->tableName)
            ->where('claimed_at IS NOT NULL')
            ->andWhere('available_at <= :now')
            ->orderBy('available_at', 'ASC')
            ->setMaxResults(1)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->executeQuery()
            ->fetchOne());

        $platform = $this->connection->getDatabasePlatform();
        $date = static fn (mixed $value): ?DateTimeImmutable => null === $value || false === $value ? null : self::readUtc($value, $platform);

        return new OutboxStatus(
            due: min($due, OutboxStatus::COUNT_CAP),
            oldestDue: $this->oldestDue($now),
            retrying: $retrying,
            oldestRetrying: $date($oldestRetrying),
            failed: $failed,
            inFlight: $inFlight,
            claimExpiredSince: $date($expiredClaim),
            capped: $capped,
        );
    }

    /**
     * Evaluates $aggregate over at most COUNT_CAP + 1 rows of $query.
     */
    private function capped(QueryBuilder $query, string $aggregate): mixed
    {
        $column = str_starts_with($aggregate, 'COUNT') ? '1 AS one' : (string) preg_replace('/^\w+\((\w+)\)$/', '$1', $aggregate);
        $inner = $query->select($column)->setMaxResults(OutboxStatus::COUNT_CAP + 1);
        $sql = sprintf('SELECT %s FROM (%s) capped', str_starts_with($aggregate, 'COUNT') ? 'COUNT(*)' : $aggregate, $inner->getSQL());

        return $this->guard(fn (): mixed => $this->connection->fetchOne($sql, $inner->getParameters(), $inner->getParameterTypes()));
    }

    /**
     * Since when the longest-waiting due row waits: its retry time, or when it was stored. One
     * probe per transport along the index (the whole pending part of the table without it).
     */
    private function oldestDue(DateTimeImmutable $now): ?DateTimeImmutable
    {
        $first = function (QueryBuilder $query): ?DateTimeImmutable {
            $value = $this->guard(static fn (): mixed => $query->executeQuery()->fetchOne());

            return null === $value || false === $value ? null : self::readUtc($value, $this->connection->getDatabasePlatform());
        };

        if (!$this->schema->hasPendingIndex()) {
            $since = array_filter([
                $first($this->pending()->select('MIN(created_at)')->andWhere('available_at IS NULL')),
                $first($this->pending()->select('MIN(available_at)')->andWhere('available_at <= :now')->setParameter('now', $now, Types::DATETIME_IMMUTABLE)),
            ]);

            return [] === $since ? null : min($since);
        }

        $since = [];
        foreach ($this->transportsExcept([]) as $transport) {
            $byTransport = static fn (QueryBuilder $query): QueryBuilder => null === $transport
                    ? $query->andWhere('transport_name IS NULL')
                    : $query->andWhere('transport_name = :transport')->setParameter('transport', $transport);
            $since[] = $first($this->ordered($byTransport($this->pending()->select('created_at')->andWhere('available_at IS NULL')), ['published_at', 'failed_at', 'transport_name', 'available_at'], ['created_at'])->setMaxResults(1));
            $since[] = $first($this->ordered($byTransport($this->pending()->select('available_at')->andWhere('available_at <= :now')->setParameter('now', $now, Types::DATETIME_IMMUTABLE)), ['published_at', 'failed_at', 'transport_name'], ['available_at'])->setMaxResults(1));
        }
        $since = array_filter($since);

        return [] === $since ? null : min($since);
    }

    public function isInTransaction(): bool
    {
        // With auto-commit off, DBAL opens a transaction when it connects: every statement is in one.
        return !$this->connection->isAutoCommit() || $this->connection->isTransactionActive();
    }

    public function fetchFailed(int $limit, array $ids = []): array
    {
        $this->readFromPrimary();
        $this->ensureTableExists();

        // The bodies (up to the size a broker rejects) only for the messages about to be signed.
        $query = $this->connection->createQueryBuilder()
            ->select('id', 'transport_name', 'created_at', 'failed_at', 'attempts', 'last_error', 'headers', ...([] === $ids ? [] : ['body']))
            ->from($this->tableName)
            ->where('published_at IS NULL')
            ->andWhere('failed_at IS NOT NULL')
            ->orderBy('failed_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($limit);
        if ([] !== $ids) {
            $query->andWhere('id IN (:ids)')->setParameter('ids', array_map(strtolower(...), $ids), ArrayParameterType::STRING);
        }
        $rows = $this->guard(static fn (): array => $query->executeQuery()->fetchAllAssociative());

        $platform = $this->connection->getDatabasePlatform();

        return array_map(static function (array $row) use ($platform): FailedOutboxMessage {
            $body = isset($row['body']) ? (string) $row['body'] : null;
            $contents = null === $body ? null : SerializedBody::inspect($body);

            return new FailedOutboxMessage(
                id: (string) $row['id'],
                transportName: null === $row['transport_name'] ? null : (string) $row['transport_name'],
                createdAt: self::readUtc($row['created_at'], $platform),
                failedAt: self::readUtc($row['failed_at'], $platform),
                attempts: (int) $row['attempts'],
                lastError: null === $row['last_error'] ? null : (string) $row['last_error'],
                messageType: self::messageType((string) $row['headers']),
                bodyClass: $contents['messageClass'] ?? null,
                digest: null === $body ? null : FailedOutboxMessage::digest($body, (string) $row['headers']),
                bodyClasses: $contents['classes'] ?? null,
                customSerializedClasses: $contents['custom'] ?? [],
            );
        }, $rows);
    }

    public function deleteFailed(array $ids): int
    {
        $this->ensureTableExists();

        $query = $this->connection->createQueryBuilder()
            ->delete($this->tableName)
            ->where('published_at IS NULL')
            ->andWhere('failed_at IS NOT NULL')
            ->andWhere('id IN (:ids)')
            ->setParameter('ids', array_map(strtolower(...), $ids), ArrayParameterType::STRING);

        $deleted = (int) $this->guard(static fn (): int|string => $query->executeStatement());
        $this->commitImplicitTransaction();

        return $deleted;
    }

    public function requeueFailed(array $ids = [], ?string $transportName = null, ?\Closure $sign = null): int
    {
        $this->ensureTableExists();

        $requeue = function (?string $id = null, ?string $signature = null) use ($ids, $transportName): int {
            $query = $this->connection->createQueryBuilder()
                ->update($this->tableName)
                ->set('failed_at', 'NULL')
                ->set('available_at', 'NULL')
                ->set('attempts', '0')
                ->set('last_error', 'NULL')
                ->set('claim_token', 'NULL')
                ->set('claimed_at', 'NULL')
                ->where('published_at IS NULL')
                ->andWhere('failed_at IS NOT NULL');

            if (null !== $transportName) {
                $query->set('transport_name', ':transport_name')->setParameter('transport_name', $transportName);
            }
            if (null !== $id) {
                $query->set('signature', ':signature')->setParameter('signature', $signature)
                    ->andWhere('id = :id')->setParameter('id', $id);
            } elseif ([] !== $ids) {
                $query->andWhere('id IN (:ids)')->setParameter('ids', array_map(strtolower(...), $ids), ArrayParameterType::STRING);
            }

            $requeued = (int) $this->guard(static fn (): int|string => $query->executeStatement());
            $this->commitImplicitTransaction();

            return $requeued;
        };

        if (null === $sign) {
            return $requeue();
        }

        // Each row is signed as stored, and requeued in the same statement.
        $query = $this->connection->createQueryBuilder()
            ->select('id', 'body', 'headers', 'transport_name', 'created_at')
            ->from($this->tableName)
            ->where('published_at IS NULL')
            ->andWhere('failed_at IS NOT NULL');
        if ([] !== $ids) {
            $query->andWhere('id IN (:ids)')->setParameter('ids', array_map(strtolower(...), $ids), ArrayParameterType::STRING);
        }
        $platform = $this->connection->getDatabasePlatform();
        $requeued = 0;
        foreach ($this->guard(static fn (): array => $query->executeQuery()->fetchAllAssociative()) as $row) {
            $message = new OutboxMessage((string) $row['id'], (string) $row['body'], (string) $row['headers'], self::readUtc($row['created_at'], $platform), null === $row['transport_name'] ? null : (string) $row['transport_name']);
            $requeued += $requeue($message->id, $sign($message));
        }

        return $requeued;
    }

    /**
     * The "type" header of Messenger's serializers, read as JSON; the body is never decoded.
     */
    private static function messageType(string $headers): ?string
    {
        try {
            $decoded = json_decode($headers, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) && is_string($decoded['type'] ?? null) ? $decoded['type'] : null;
    }

    public function setup(?\Closure $onWait = null): void
    {
        // With auto-commit off, DBAL keeps a transaction of its own open, in which the setup would
        // refuse to change the table: it runs with auto-commit on (DBAL commits that transaction).
        $implicit = !$this->connection->isAutoCommit() && $this->connection->getTransactionNestingLevel() <= 1;
        if ($implicit) {
            $this->connection->setAutoCommit(true);
        }

        try {
            $this->schema->setup($onWait);
        } finally {
            if ($implicit) {
                $this->connection->setAutoCommit(false);
            }
        }
        $this->setupDone = true;
    }

    /**
     * @internal
     */
    public function dispatchInUnitOfWork(\Closure $dispatch): mixed
    {
        // Only with auto-commit off: the handlers the relay runs in its own process would otherwise
        // share DBAL's implicit transaction with the relay's writes (a failed handler's writes
        // committed with the next publish mark, or an aborted PostgreSQL transaction failing them).
        if ($this->connection->isAutoCommit()) {
            return $dispatch();
        }
        // DBAL opens its implicit transaction when it connects.
        $this->connection->getNativeConnection();
        if (1 !== $this->connection->getTransactionNestingLevel()) {
            return $dispatch();
        }

        $this->connection->beginTransaction();
        try {
            $result = $dispatch();
        } catch (\Throwable $exception) {
            try {
                $this->connection->rollBack();
            } catch (\Throwable) {
                $this->discardConnection();

                // The error may be a missing savepoint of a middleware (doctrine_transaction), not the cause.
                throw new \RuntimeException(sprintf('The database rolled back the unit of work of this message itself (a deadlock or a lock wait timeout?): %s', $exception->getMessage()), 0, $exception);
            }

            throw $exception;
        }
        try {
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->discardConnection();

            throw $exception;
        }
        $this->commitImplicitTransaction();

        return $result;
    }

    /**
     * The database ended the transaction itself (a deadlock on MySQL rolls back the savepoint too):
     * DBAL's nesting level no longer matches the session. Everything the relay wrote is committed
     * already (publish marks wait in memory), so the next statement starts over on a new connection.
     */
    private function discardConnection(): void
    {
        $this->connection->close();
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
        $this->readFromPrimary();

        return $this->schema->pendingChanges();
    }

    /**
     * A primary/read-replica connection reads from a replica until it writes: the relay would act
     * on stale rows (and the commands would report them), so the outbox always reads the primary.
     */
    private function readFromPrimary(): void
    {
        if ($this->connection instanceof PrimaryReadReplicaConnection) {
            $this->connection->ensureConnectedToPrimary();
        }
    }

    /**
     * Adds the outbox table definition to an existing Schema object.
     *
     * Useful for Doctrine schema listeners or migration generation.
     */
    public static function addTableToSchema(Schema $schema, string $tableName = 'somework_cqrs_outbox'): Table
    {
        return DbalOutboxSchema::addToSchema($schema, $tableName, $tableName);
    }

    /**
     * Adds the table as $name, with the columns and indexes of the configured $tableName: MySQL lists
     * a "database.table" of the connection's own database as "table".
     *
     * @internal
     */
    public static function addTableToSchemaAs(Schema $schema, string $name, string $tableName): Table
    {
        return DbalOutboxSchema::addToSchema($schema, $name, $tableName);
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
        // Each transport contributes one row per round: reading limit / transports rows of each
        // is enough, unless some have fewer; then the ones that may have more are read deeper.
        $queues = [];
        $depth = max(1, (int) ceil($limit / max(1, count($transports))));
        $pending = array_map(static fn (?string $transport): string => $transport ?? "\0", $transports);
        while ([] !== $pending) {
            $new = $this->dueIds(array_fill_keys($pending, $depth), 'available_at IS NULL', ['available_at'], ['created_at', 'id'], 'created_at');
            $retryLimits = [];
            foreach ($pending as $key) {
                $count = count($new[$key] ?? []);
                if ($count < $depth) {
                    $retryLimits[$key] = $depth - $count;
                }
            }
            $retries = [] === $retryLimits ? [] : $this->dueIds($retryLimits, 'available_at <= :now', [], ['available_at', 'created_at', 'id'], 'available_at');

            $deeper = [];
            foreach ($pending as $key) {
                $due = [...($new[$key] ?? []), ...($retries[$key] ?? [])];
                unset($queues[$key]);
                if ([] !== $due) {
                    $queues[$key] = $due;
                }
                if (count($due) === $depth) {
                    $deeper[] = $key;
                }
            }

            $available = array_sum(array_map(count(...), $queues));
            if ($available >= $limit || [] === $deeper || $depth >= $limit) {
                break;
            }
            $depth = min($limit, $depth * 2);
            $pending = $deeper;
        }
        // In the order of the transports, as the ties rotate over it.
        $queues = array_values(array_filter(array_map(static fn (?string $transport): ?array => $queues[$transport ?? "\0"] ?? null, $transports)));

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
     * Ids of the due rows of each transport, in index order (the index covers every query), with
     * the time since which each row is due: one statement for up to 50 transports, whose queries
     * are combined with UNION ALL.
     *
     * @param array<array-key, int> $limits      Rows to read per transport key ("\0" for the rows without a transport name; PHP turns numeric names into integer keys)
     * @param list<string>          $nullColumns Index columns after transport_name the condition restricts to NULL
     * @param list<string>          $order
     *
     * @return array<array-key, list<array{string, string}>> By transport key
     */
    private function dueIds(array $limits, string $condition, array $nullColumns, array $order, string $dueSince): array
    {
        $now = self::now();
        $due = [];
        foreach (array_chunk($limits, self::TRANSPORTS_PER_STATEMENT, true) as $chunk) {
            $branches = [];
            $keys = [];
            $parameters = [];
            $types = [];
            foreach ($chunk as $key => $limit) {
                $n = count($branches);
                $keys[$n] = $key;
                // Rows are assigned to their branch, not to their transport_name: under a
                // case-insensitive collation (MySQL), "async" also matches "ASYNC".
                $query = $this->pending()->select('id', $dueSince.' AS due_since', 'created_at AS stored_at', $n.' AS branch')->andWhere(str_replace(':now', ':now_'.$n, $condition))->setMaxResults($limit);
                if ("\0" === (string) $key) {
                    $query->andWhere('transport_name IS NULL');
                } else {
                    $query->andWhere('transport_name = :transport_'.$n)->setParameter('transport_'.$n, (string) $key);
                }
                if (str_contains($condition, ':now')) {
                    $query->setParameter('now_'.$n, $now, Types::DATETIME_IMMUTABLE);
                }
                // A derived table per branch: ORDER BY and LIMIT apply to it (also on SQLite).
                $branches[] = sprintf('SELECT * FROM (%s) due_%d', $this->ordered($query, ['published_at', 'failed_at', 'transport_name', ...$nullColumns], $order)->getSQL(), $n);
                $parameters += $query->getParameters();
                $types += $query->getParameterTypes();
            }

            $rows = $this->guard(fn (): array => $this->connection->fetchAllAssociative(implode(' UNION ALL ', $branches), $parameters, $types));
            foreach ($rows as $row) {
                $due[$keys[(int) $row['branch']]][] = [(string) $row['id'], (string) $row['due_since'], (string) $row['stored_at']];
            }
        }

        // UNION ALL keeps no order across branches: restore the index order of each transport.
        $ordered = [];
        foreach ($due as $key => $rows) {
            usort($rows, static fn (array $a, array $b): int => [$a[1], $a[2], $a[0]] <=> [$b[1], $b[2], $b[0]]);
            $ordered[$key] = array_map(static fn (array $row): array => [$row[0], $row[1]], $rows);
        }

        return $ordered;
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
            ->select('id', 'body', 'headers', 'transport_name', 'created_at', 'available_at', 'attempts', 'last_error', 'claimed_at', 'signature')
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
     * Runs a statement of the relay again once after a deadlock or a lock wait timeout (outside a
     * transaction of the caller, which the database rolled back).
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function retryOnce(\Closure $operation): mixed
    {
        return $this->retry(1, $operation);
    }

    /**
     * Runs an idempotent statement of the relay again after a deadlock or a serialization failure,
     * up to $retries times with a growing random delay (outside a transaction of the caller).
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function retry(int $retries, \Closure $operation): mixed
    {
        for ($attempt = 0;; ++$attempt) {
            try {
                return $operation();
            } catch (RetryableException $exception) {
                if ($attempt >= $retries || $this->connection->isTransactionActive()) {
                    throw $exception;
                }

                usleep(random_int(10_000, 50_000) * ($attempt + 1));
            }
        }
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

    /**
     * With auto-commit off, DBAL keeps every statement in a transaction it opened itself, rolled back
     * when the process exits: the writes of the relay and the maintenance commands are committed.
     * Messages are stored in the caller's transaction (never committed here), and a transaction the
     * application opened on top (nesting level above 1) is left alone.
     */
    private function commitImplicitTransaction(): void
    {
        if (!$this->connection->isAutoCommit() && 1 === $this->connection->getTransactionNestingLevel()) {
            $this->connection->commit();
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
