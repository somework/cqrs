<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use SomeWork\CqrsBundle\Contract\OutboxStorage;

use function sprintf;

/**
 * DBAL-backed implementation of the transactional outbox storage.
 *
 * @internal Promote to @api in v3.1 after real-world validation
 */
final class DbalOutboxStorage implements OutboxStorage
{
    /** Intentionally mutable for lazy table creation — process-local, not distributed across workers. See autoSetup(). */
    private bool $setupDone = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName = 'somework_cqrs_outbox',
        private readonly bool $autoSetup = true,
    ) {
    }

    public function store(OutboxMessage $message): void
    {
        $this->autoSetup();

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
    public function fetchUnpublished(int $limit): array
    {
        $this->autoSetup();

        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('id', 'body', 'headers', 'transport_name', 'created_at')
            ->from($this->tableName)
            ->where('published_at IS NULL')
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($limit);

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        return array_map(
            static fn (array $row): OutboxMessage => new OutboxMessage(
                id: $row['id'],
                body: $row['body'],
                headers: $row['headers'],
                createdAt: new DateTimeImmutable($row['created_at']),
                transportName: $row['transport_name'],
            ),
            $rows,
        );
    }

    public function markPublished(string $id): void
    {
        $this->autoSetup();

        $affectedRows = $this->connection->update(
            $this->tableName,
            ['published_at' => new DateTimeImmutable()],
            ['id' => $id],
            ['published_at' => Types::DATETIME_IMMUTABLE],
        );

        if (0 === $affectedRows) {
            throw new \RuntimeException(sprintf('Outbox message "%s" not found in table "%s" — cannot mark as published.', $id, $this->tableName));
        }
    }

    /**
     * Creates the outbox table if it does not exist.
     *
     * Catches TableExistsException for race-condition safety when
     * multiple processes attempt setup concurrently.
     */
    public function setup(): void
    {
        $table = self::buildTableDefinition($this->tableName);

        try {
            $this->connection->createSchemaManager()->createTable($table);
        } catch (TableExistsException) {
            // Table was created by another process -- safe to ignore.
        }

        $this->setupDone = true;
    }

    /**
     * Adds the outbox table definition to an existing Schema object.
     *
     * Useful for Doctrine schema subscribers or migration generation.
     */
    public static function addTableToSchema(Schema $schema, string $tableName = 'somework_cqrs_outbox'): Table
    {
        $table = $schema->createTable($tableName);

        self::configureTableColumns($table);
        self::configureTableIndexes($table, $tableName);

        return $table;
    }

    private function autoSetup(): void
    {
        if ($this->autoSetup && !$this->setupDone) {
            $this->setup();
        }
    }

    private static function buildTableDefinition(string $tableName): Table
    {
        $table = new Table($tableName);

        self::configureTableColumns($table);
        self::configureTableIndexes($table, $tableName);

        return $table;
    }

    private static function configureTableColumns(Table $table): void
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

        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->setUnquotedColumnNames('id')
                ->create(),
        );
    }

    private static function configureTableIndexes(Table $table, string $tableName): void
    {
        $table->addIndex(
            ['published_at', 'created_at'],
            'idx_'.str_replace('.', '_', $tableName).'_published_created',
        );
    }
}
