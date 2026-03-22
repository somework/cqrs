# Transactional outbox

The bundle provides a transactional outbox pattern that persists async messages in
the same database transaction as your business logic, then relays them to transports
afterward. This eliminates dual-write consistency failures where a message is
dispatched but the database transaction rolls back (or vice versa).

## How it works

Instead of dispatching directly to a transport, messages are stored in an outbox
table within the current database transaction. A separate relay process reads
unpublished messages and dispatches them to Messenger transports in stored order.
This guarantees that messages are only dispatched if the originating transaction
commits.

The three-step flow:

1. Business logic writes to the database and calls `OutboxStorage::store()` in the
   same transaction
2. Transaction commits -- the message is now safely persisted
3. `somework:cqrs:outbox:relay` reads unpublished messages and dispatches them via
   Messenger

## Requirements

`doctrine/dbal` must be installed:

```bash
composer require doctrine/dbal
```

The bundle declares `doctrine/dbal` as a `suggest` dependency. When not installed,
outbox services are not registered (`class_exists` guard in the extension).

## Configuration

```yaml
somework_cqrs:
    outbox:
        enabled: true
        table_name: somework_cqrs_outbox
```

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `false` | Enables the transactional outbox. Requires `doctrine/dbal`. When `false`, no outbox services are registered. |
| `table_name` | `somework_cqrs_outbox` | Database table name for outbox messages. |

## Table schema

The outbox table has the following columns:

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | GUID | No | Unique message identifier (primary key) |
| `body` | TEXT | No | Serialized message body |
| `headers` | TEXT | No | JSON-encoded message headers |
| `transport_name` | VARCHAR(190) | Yes | Target transport name |
| `created_at` | DATETIME_IMMUTABLE | No | When the message was stored |
| `published_at` | DATETIME_IMMUTABLE | Yes | When the message was relayed (null = unpublished) |

An index on `(published_at, created_at)` optimizes the relay query for unpublished
messages.

## Schema auto-generation

Two mechanisms handle table creation:

**Auto-setup (default).** `DbalOutboxStorage` automatically creates the table on
first use via `setup()`. This catches `TableExistsException` for race-condition
safety when multiple processes start simultaneously.

**Doctrine ORM subscriber.** When `doctrine/orm` is installed,
`OutboxSchemaSubscriber` listens to the `postGenerateSchema` event and adds the
outbox table to Doctrine's schema. This means `doctrine:schema:update` and
`doctrine:migrations:diff` will include the outbox table automatically.

## Relay command

Relay unpublished messages to their transports:

```bash
# Relay up to 100 unpublished messages (default)
php bin/console somework:cqrs:outbox:relay

# Relay with custom limit
php bin/console somework:cqrs:outbox:relay --limit=50
```

| Option | Default | Description |
|--------|---------|-------------|
| `--limit`, `-l` | `100` | Maximum number of messages to relay per run. |

The relay command deserializes each message using Symfony's Messenger serializer,
dispatches it via the message bus, and marks it as published. Failed messages are
logged and skipped -- the command continues with remaining messages. Exit code `0`
on full success, `1` if any message failed.

Run the relay command periodically via cron or supervisor for continuous relay:

```bash
# Crontab (every minute)
* * * * * php /path/to/project/bin/console somework:cqrs:outbox:relay --limit=100
```

## OutboxStorage interface

The `OutboxStorage` interface defines three methods:

```php
use SomeWork\CqrsBundle\Contract\OutboxStorage;

// Store a message in the outbox (within your transaction)
$outboxStorage->store($outboxMessage);

// Fetch unpublished messages (used by relay command)
$messages = $outboxStorage->fetchUnpublished(limit: 100);

// Mark a message as published (used by relay command)
$outboxStorage->markPublished($messageId);
```

`OutboxStorage` is marked `@internal` in v3.0. The interface will be promoted to
`@api` in v3.1 after real-world validation. Custom implementations are possible but
the interface may change in minor releases.

## Limitations

- **Polling-only relay.** Messages are relayed by running the relay command
  periodically. Change Data Capture (CDC) mode is not supported.

- **No exactly-once delivery.** If the relay process crashes after dispatching but
  before marking as published, the message may be relayed again on the next run.
  Consumers should be idempotent.

- **Single database.** The outbox table must be in the same database as your
  business data to share the transaction. Cross-database transactions are not
  supported.

- **OutboxStorage is @internal.** The interface may change in minor releases. It
  will be promoted to `@api` in v3.1.
