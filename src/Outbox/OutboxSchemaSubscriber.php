<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

use function explode;
use function strtolower;

/**
 * Adds the outbox table to the Doctrine ORM schema on postGenerateSchema.
 *
 * Requires doctrine/orm. Only registered when class_exists(ToolEvents::class).
 *
 * @internal
 */
final class OutboxSchemaSubscriber
{
    public function __construct(
        private readonly string $tableName = 'somework_cqrs_outbox',
    ) {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        $name = $this->tableName;

        $parts = explode('.', $name, 2);
        if (isset($parts[1])) {
            $connection = $event->getEntityManager()->getConnection();
            // MySQL qualifies names with the database, and the schema only holds the connection's
            // own database: a table in another one is left to "somework:cqrs:outbox:setup".
            if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                if (strtolower((string) $connection->getDatabase()) !== strtolower($parts[0])) {
                    return;
                }
                $name = $parts[1];
            }
        }

        if ($schema->hasTable($name)) {
            return;
        }

        DbalOutboxStorage::addTableToSchemaAs($schema, $name, $this->tableName);
    }
}
