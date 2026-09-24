# Transactional outbox

Suppose a handler commits a database change and then sends a message to a broker. It can
fail between the two steps: the change is saved and the message is lost, or the message
goes out for a change that was rolled back. With the outbox, you store the message in a
database table **in the same transaction** as the business change. The
`somework:cqrs:outbox:relay` command later sends the stored messages to Messenger.

!!! note "Stability"
    `OutboxStorage`, `OutboxMessage` and `DbalOutboxStorage` are marked `@internal` for now.
    They may change in a 0.x minor release before they are promoted to `@api`. Use a
    `^0.5` constraint, which only allows 0.5.x releases.

## How it works

1. Inside your database transaction, you write the business data and call
   `OutboxStorage::store()` with an `OutboxMessage` built from a Messenger envelope.
2. The transaction commits. The message row is saved only if the business change is.
3. `somework:cqrs:outbox:relay` reads unpublished rows oldest first, decodes each one, and
   dispatches it through Messenger's default bus. The message goes to the transport stored
   with the row, or follows the Messenger routing when no transport was stored. Then the
   relay marks the row as published.

Writing to the outbox is always explicit. The CQRS buses (`EventBus::dispatch()` and so on)
never write to the outbox.

## Requirements

- `doctrine/dbal` 4. Enabling the outbox without it fails container compilation with an
  `InvalidConfigurationException`.
- `doctrine/doctrine-bundle`, which provides the `doctrine.dbal.<name>_connection` service
  the storage uses.
- Optional: `symfony/lock`, so only one relay runs at a time (see [Relaying](#relaying)).
- Optional: `doctrine/orm`, which adds the outbox table to schema generation and Doctrine
  migrations (see [Creating the table](#creating-the-table)).

```bash
composer require doctrine/dbal doctrine/doctrine-bundle symfony/lock
```

## Configuration

```yaml
# config/packages/somework_cqrs.yaml
somework_cqrs:
    outbox:
        enabled: true
        table_name: somework_cqrs_outbox
        connection: default
        serializer: messenger.default_serializer
        auto_setup: true
```

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `false` | Registers the outbox storage and the three console commands. It decides which services exist, so it must be a plain boolean, not an `%env()%` value. |
| `table_name` | `somework_cqrs_outbox` | Name of the outbox table. |
| `connection` | `default` | DBAL connection name. The storage uses the service `doctrine.dbal.<name>_connection`. Use the connection that holds your business data, otherwise `store()` is not part of the business transaction. |
| `serializer` | `messenger.default_serializer` | Messenger serializer service id. It is exposed as the alias `somework_cqrs.outbox.serializer` for code that writes to the outbox, and the relay uses it to decode rows. |
| `auto_setup` | `true` | Creates the table on first use if it is missing, but never inside an open transaction. Set it to `false` when migrations manage the table. |

## Writing to the outbox

Build the row with
`OutboxMessage::fromEnvelope(Envelope $envelope, SerializerInterface $serializer, ?string $transportName = null, ?DateTimeImmutable $createdAt = null)`
and pass it to `OutboxStorage::store()` inside your transaction:

```php
<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Event\OrderPlaced;
use Doctrine\DBAL\Connection;
use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

#[AsCommandHandler(command: PlaceOrder::class)]
final class PlaceOrderHandler
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OutboxStorage $outbox,
        #[Autowire(service: 'somework_cqrs.outbox.serializer')]
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function __invoke(PlaceOrder $command): mixed
    {
        $this->connection->transactional(function () use ($command): void {
            $this->connection->insert('orders', [
                'id' => $command->orderId,
                'customer_id' => $command->customerId,
            ]);

            $envelope = new Envelope(new OrderPlaced($command->orderId), [
                MessageMetadataStamp::createWithRandomCorrelationId(),
                new BusNameStamp('messenger.bus.events_async'),
            ]);

            $this->outbox->store(OutboxMessage::fromEnvelope($envelope, $this->serializer, 'async_events'));
        });

        return null;
    }
}
```

The main points:

- **Inject `somework_cqrs.outbox.serializer`**, not a transport's serializer. The relay
  decodes rows with this same service. The alias points to the `outbox.serializer` option
  (by default `messenger.default_serializer`).
- **Use the same connection.** `Connection` must be the connection named in
  `outbox.connection`. With Doctrine ORM, call `store()` inside
  `EntityManagerInterface::wrapInTransaction()`. The entity manager of that connection
  shares the same `Connection` instance.
- **The stamp pipeline does not run.** Neither writing to the outbox nor relaying goes
  through the CQRS buses, so the bundle adds no metadata, retry, serializer or transport
  stamps. Add the stamps you need to the envelope yourself. They are serialized with the
  message.
- **Choose the transport.** The third argument is the transport name. The relay sends the
  message there with Messenger's `TransportNamesStamp`, which overrides the routing. With
  `null`, `framework.messenger.routing` decides.
- **Choose the bus in multi-bus setups.** A worker dispatches a received message on the bus
  named in its `BusNameStamp`. The relay dispatches through `messenger.default_bus`, which
  stamps the default bus name when the envelope has none. If your handlers live on another
  bus (for example the async event bus), add `new BusNameStamp('<bus id>')` as shown above,
  or run the worker with `messenger:consume --bus=<bus id>`.

`fromEnvelope()` gives the row a time-ordered UUIDv7 id. Rows stored in the same
millisecond by one process keep their order.

## Creating the table

The table has to exist before the first `store()`. There are three ways to create it:

**Setup command.** Run it once per environment, for example in your deployment script. It
creates the table if it is missing and does nothing otherwise:

```bash
bin/console somework:cqrs:outbox:setup
```

**Doctrine migrations.** When `doctrine/orm` is installed, the bundle registers
`OutboxSchemaSubscriber`, a `postGenerateSchema` listener that is scoped to the configured
`outbox.connection`. It adds the outbox table to the schema of that connection, so
`doctrine:migrations:diff` and `doctrine:schema:update` include it. Set
`auto_setup: false` once a migration manages the table. For DBAL-only migrations without
the ORM, add the table in the migration yourself:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;

final class Version20260101000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        DbalOutboxStorage::addTableToSchema($schema, 'somework_cqrs_outbox');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('somework_cqrs_outbox');
    }
}
```

**`auto_setup: true` (default).** The first time a process uses the storage, it checks
whether the table exists and creates it if needed. It never creates the table inside an open
transaction: DDL would implicitly commit your transaction on MySQL or abort it on
PostgreSQL. `store()` normally runs inside your transaction, so a missing table then raises
a `LogicException` that tells you to run `somework:cqrs:outbox:setup`. In practice,
`auto_setup` only creates the table when the relay or purge command runs first. Do not rely
on it for the write path.

### Table schema

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | GUID | No | Primary key (UUIDv7) |
| `body` | TEXT | No | Encoded message body |
| `headers` | TEXT | No | JSON-encoded headers from the serializer |
| `transport_name` | VARCHAR(190) | Yes | Target transport; `null` follows the Messenger routing |
| `created_at` | DATETIME_IMMUTABLE | No | When the row was built |
| `published_at` | DATETIME_IMMUTABLE | Yes | When the relay sent it (`null` = unpublished) |

An index on `(published_at, created_at)`, named `idx_<table>_published_created`, serves the
relay query.

## Relaying

```bash
bin/console somework:cqrs:outbox:relay            # up to 100 messages
bin/console somework:cqrs:outbox:relay --limit=500
```

| Option | Default | Description |
|--------|---------|-------------|
| `--limit`, `-l` | `100` | Maximum number of messages to relay in this run (positive integer). |

For each unpublished row, oldest first (`created_at`, then `id`), the relay:

1. decodes the row with the outbox serializer;
2. adds a `TransportNamesStamp` with the stored transport name, if one was stored;
3. dispatches the envelope through `messenger.default_bus`;
4. marks the row as published.

What happens in special cases:

- **Failing rows are skipped and reported.** If decoding, dispatching or marking a row
  fails, the relay prints `Failed to relay message "<id>": <reason>` and moves on. The row
  stays unpublished, so the next run tries it again. When any row failed, the command exits
  with code `1`.
- **Messages handled inline trigger a warning.** If no transport received a message (no
  stored transport name and no routing), the default bus handles it synchronously inside the
  relay process. The relay prints a warning and still marks the row as published. Store a
  transport name or add routing to avoid this.
- **Only one relay runs at a time.** When `symfony/lock` is installed, the command takes a
  lock named after itself. The lock comes from the application's `lock.factory` service.
  FrameworkBundle registers that service when `framework.lock` is enabled, which is the
  default once `symfony/lock` is installed. Without that service, Symfony's
  `LockableTrait` creates a local semaphore or flock store. The default stores only guard
  one host, so configure a shared store in `framework.lock` (Redis, a database, …) when
  cron runs the relay on several servers. A second relay that finds the lock taken prints
  `Another outbox relay is already running.` and exits with `0`. Without `symfony/lock`,
  nothing stops two relays from overlapping, and overlapping relays send the same rows
  twice.

| Exit code | Meaning |
|-----------|---------|
| `0` | All selected rows were relayed, there was nothing to relay, or another relay holds the lock |
| `1` | At least one row failed |
| `2` | Invalid `--limit` |

The relay handles at most `--limit` rows per run and then exits. Run it on a schedule, for
example from cron:

```bash
* * * * * /path/to/project/bin/console somework:cqrs:outbox:relay --limit=500
```

The rows do not track failed attempts. A row that can never be relayed (for example, its
message class was removed) fails on every run and makes every run exit with `1`. Fix or
delete such rows by hand.

## Purging published rows

Published rows stay in the table until you purge them:

```bash
bin/console somework:cqrs:outbox:purge                        # published more than 7 days ago
bin/console somework:cqrs:outbox:purge --older-than="12 hours"
```

`--older-than` takes a relative date such as `7 days`, `12 hours` or `1 month`. The default
is `7 days`. Only rows whose `published_at` is older than that are deleted. Unpublished rows
are never deleted. An invalid value exits with code `2`. Schedule the purge, for example
daily.

## Delivery guarantees

- **Atomic write.** The row exists only if your transaction commits.
- **At-least-once delivery.** The relay marks a row as published *after* dispatching it. A
  crash between the two steps, a failed `markPublished()`, or two relays without a shared
  lock can send the same message twice. **Consumers must be idempotent**, for example by
  recording processed message ids under a unique constraint. The bundle's
  [idempotency bridge](idempotency.md) does not cover this case: relayed messages do not
  pass through the CQRS stamp pipeline.
- **Order.** Rows are relayed in the order they were stored. A row that fails is skipped
  for the rest of the run, so later rows can overtake it. Several workers consuming the
  transport can also process messages out of order.
- **Latency.** Messages leave the outbox only when the relay runs. Your schedule sets the
  delay.

## Custom storage

The DBAL storage covers relational databases that DoctrineBundle can connect to. To keep
the outbox somewhere else, implement `SomeWork\CqrsBundle\Contract\OutboxStorage`:

```php
<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use DateTimeImmutable;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;

interface OutboxStorage
{
    /**
     * Stores a message; call it inside the database transaction of the business change.
     */
    public function store(OutboxMessage $message): void;

    /**
     * Returns unpublished messages, oldest first.
     *
     * @param int $offset number of unpublished messages to skip (the relay skips messages that failed in the current run)
     *
     * @return list<OutboxMessage>
     */
    public function fetchUnpublished(int $limit, int $offset = 0): array;

    /**
     * Marks a message as published. Marking an already published message is a no-op.
     *
     * @throws \RuntimeException when the message does not exist
     */
    public function markPublished(string $id): void;

    /**
     * Deletes messages published before the given date and returns how many were deleted.
     */
    public function purgePublished(DateTimeImmutable $publishedBefore): int;
}
```

An implementation must meet these rules:

- `store()` must write through the same transaction as your business data. Otherwise the
  outbox guarantees nothing.
- `fetchUnpublished()` returns unpublished messages only, in a stable oldest-first order.
  `$offset` skips that many unpublished messages. The relay passes the number of rows that
  failed earlier in the same run.
- Rebuild each message with
  `new OutboxMessage(string $id, string $body, string $headers, DateTimeImmutable $createdAt, ?string $transportName = null)`.
  Keep the values exactly as stored. `$headers` is the JSON string produced by
  `fromEnvelope()`, and `$id` and `$body` must not be empty.

To use your implementation, override the storage service id in your application:

```yaml
# config/services.yaml
services:
    somework_cqrs.outbox.storage:
        class: App\Outbox\MongoOutboxStorage
        autowire: true
```

The relay and purge commands, and the `OutboxStorage` alias, then use your class. There are
three caveats:

- `outbox.enabled: true` still requires `doctrine/dbal` to be installed.
- `table_name`, `connection` and `auto_setup` only configure the DBAL storage.
- `somework:cqrs:outbox:setup` only works with `DbalOutboxStorage` and fails with a type
  error otherwise. Create your storage's schema yourself.

## Limitations

- **Polling only.** Messages leave the outbox when the relay runs. Change data capture is
  not supported.
- **One database.** The outbox table must be reachable through the same connection and
  transaction as your business data. Distributed transactions are not supported.
- **No dead-lettering of rows.** Rows that always fail stay unpublished until you handle
  them. Once a row is relayed, Messenger's retry and failure transports take over.
