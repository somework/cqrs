<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use SomeWork\CqrsBundle\Contract\OutboxStorage;

use function array_map;
use function class_exists;
use function sha1;
use function sprintf;
use function strlen;
use function substr;

/**
 * DBAL-backed implementation of the transactional outbox storage.
 *
 * With auto-setup enabled the table is created on first use, but never inside an open
 * transaction: DDL would commit the caller's transaction implicitly (MySQL) or abort it
 * (PostgreSQL). Create the table up front with "somework:cqrs:outbox:setup" or a migration
 * (the Doctrine ORM schema listener adds it to generated migrations).
 *
 * @internal Promote to @api in a future minor release after real-world validation
 */
final class DbalOutboxStorage implements OutboxStorage
{
    /** Process-local cache of the "table exists" check. */
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

        $this->connection->insert($this->tableName, [
            'id' => $message->id,
            'body' => $message->body,
            'headers' => $message->headers,
            'transport_name' => $message->transportName,
            'created_at' => $message->createdAt,
            'published_at' => null,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
            'published_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * @return list<OutboxMessage>
     */
    public function fetchUnpublished(int $limit, int $offset = 0): array
    {
        $this->ensureTableExists();

        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'body', 'headers', 'transport_name', 'created_at')
            ->from($this->tableName)
            ->where('published_at IS NULL')
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $platform = $this->connection->getDatabasePlatform();
        $dateType = Type::getType(Types::DATETIME_IMMUTABLE);

        return array_map(
            static fn (array $row): OutboxMessage => new OutboxMessage(
                id: (string) $row['id'],
                body: (string) $row['body'],
                headers: (string) $row['headers'],
                createdAt: $dateType->convertToPHPValue($row['created_at'], $platform),
                transportName: null === $row['transport_name'] ? null : (string) $row['transport_name'],
            ),
            $rows,
        );
    }

    public function markPublished(string $id): void
    {
        $this->ensureTableExists();

        $updated = $this->connection->createQueryBuilder()
            ->update($this->tableName)
            ->set('published_at', ':published_at')
            ->where('id = :id')
            ->andWhere('published_at IS NULL')
            ->setParameter('published_at', new DateTimeImmutable(), Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id)
            ->executeStatement();

        if (0 !== $updated) {
            return;
        }

        // Already published (e.g. by a concurrent relay): nothing to do. Unknown ids are an error.
        $exists = $this->connection->createQueryBuilder()
            ->select('1')
            ->from($this->tableName)
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();

        if (false === $exists) {
            throw new \RuntimeException(sprintf('Outbox message "%s" not found in table "%s" — cannot mark as published.', $id, $this->tableName));
        }
    }

    public function purgePublished(DateTimeImmutable $publishedBefore): int
    {
        $this->ensureTableExists();

        return (int) $this->connection->createQueryBuilder()
            ->delete($this->tableName)
            ->where('published_at IS NOT NULL')
            ->andWhere('published_at < :before')
            ->setParameter('before', $publishedBefore, Types::DATETIME_IMMUTABLE)
            ->executeStatement();
    }

    /**
     * Creates the outbox table if it does not exist.
     *
     * @throws \LogicException when called inside an open transaction
     */
    public function setup(): void
    {
        if ($this->tableExists()) {
            $this->setupDone = true;

            return;
        }

        if ($this->connection->isTransactionActive()) {
            throw new \LogicException(sprintf('The outbox table "%s" does not exist and cannot be created inside an open database transaction. Create it beforehand with "bin/console somework:cqrs:outbox:setup" or a Doctrine migration.', $this->tableName));
        }

        try {
            $this->connection->createSchemaManager()->createTable(self::buildTableDefinition($this->tableName));
        } catch (TableExistsException) {
            // Created concurrently by another process.
        }

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
        if ($this->setupDone || !$this->autoSetup) {
            return;
        }

        $this->setup();
    }

    private function tableExists(): bool
    {
        return $this->connection->createSchemaManager()->tablesExist([$this->tableName]);
    }

    private static function buildTableDefinition(string $tableName): Table
    {
        $table = new Table($tableName);

        self::configureTable($table, $tableName);

        return $table;
    }

    private static function configureTable(Table $table, string $tableName): void
    {
        $table->addColumn('id', Types::GUID)
            ->setNotnull(true);

        $table->addColumn('body', Types::TEXT)
            ->setNotnull(true);

        $table->addColumn('headers', Types::TEXT)
            ->setNotnull(true);

        $table->addColumn('transport_name', Types::STRING)
            ->setLength(190)
            ->setNotnull(false);

        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE)
            ->setNotnull(true);

        $table->addColumn('published_at', Types::DATETIME_IMMUTABLE)
            ->setNotnull(false);

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
     * Keeps the historical "idx_<table>_published_created" name, falling back to a hashed
     * name when it would exceed the 63-character identifier limit of PostgreSQL/MySQL.
     */
    private static function indexName(string $tableName): string
    {
        $name = 'idx_'.str_replace('.', '_', $tableName).'_published_created';

        return strlen($name) <= 63 ? $name : 'idx_'.substr(sha1($tableName), 0, 16).'_published_created';
    }
}
