<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

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

        if ($schema->hasTable($this->tableName)) {
            return;
        }

        DbalOutboxStorage::addTableToSchema($schema, $this->tableName);
    }
}
