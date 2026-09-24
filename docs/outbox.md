# Transactional outbox

Suppose a handler commits a database change and then sends a message to a broker. It can
fail between the two steps: the change is saved and the message is lost, or the message
goes out for a change that was rolled back. With the outbox, you store the message in a
database table **in the same transaction** as the business change. The
`somework:cqrs:outbox:relay` command later sends the stored messages to Messenger.

!!! note "Stability"
    `OutboxStorage`, `OutboxMessage` and `DbalOutboxStorage` are part of the public API
    (`@api`). Type-hint the `OutboxStorage` interface in your code; `DbalOutboxStorage` is
    public for `addTableToSchema()` in migrations and for the setup and failed-message tools.

## How it works

1. Inside your database transaction, you write the business data and call
   `OutboxStorage::store()` with an `OutboxMessage` built from a Messenger envelope.
2. The transaction commits. The message row is saved only if the business change is.
3. `somework:cqrs:outbox:relay` reads unpublished rows oldest first, decodes each one, and
   dispatches it through the Messenger bus of its type (see [Relaying](#relaying)). The message
   goes to the transport stored with the row, or follows the Messenger routing when no
   transport was stored. Then the relay marks the row as published. A row that fails is
   retried later with an increasing delay, and given up after `max_attempts` attempts (see
   [Failures](#failures)).

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
        max_attempts: 10
```

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `false` | Registers the outbox storage and the four console commands. It decides which services exist, so it must be a plain boolean, not an `%env()%` value. |
| `table_name` | `somework_cqrs_outbox` | Name of the outbox table: letters, digits and underscores, optionally `schema.table`. A word reserved in MySQL, MariaDB, PostgreSQL or SQLite (such as `order` or `user`) is a configuration error, because the queries do not quote the name. |
| `connection` | `default` | DBAL connection name. The storage uses the service `doctrine.dbal.<name>_connection`. Use the connection that holds your business data, otherwise `store()` is not part of the business transaction. |
| `serializer` | `messenger.default_serializer` | Messenger serializer service id. It is exposed as the alias `somework_cqrs.outbox.serializer` for code that writes to the outbox, and the relay uses it to decode rows. |
| `auto_setup` | `true` | Creates the table on first use if it is missing, or adds the columns a table of an earlier version lacks, but never inside an open transaction. Set it to `false` when migrations manage the table. |
| `max_attempts` | `10` | Attempts after which the relay gives up on a row that cannot be decoded or sent (at least 1). See [Failures](#failures). |

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
- **The bus is chosen for you.** The relay dispatches commands on `buses.command_async`
  (or `buses.command`), events on `buses.event_async` (or `buses.event`), queries on
  `buses.query`, and anything else on the default bus. That bus adds its `BusNameStamp`, so
  the worker hands the message to the bus where its handlers are registered. A
  `BusNameStamp` you store yourself is kept.

`fromEnvelope()` gives the row a time-ordered UUIDv7 id. Rows stored in the same
millisecond by one process keep their order.

## Creating the table

The table has to exist before the first `store()`. There are three ways to create it:

**Setup command.** Run it once per environment, for example in your deployment script. It
creates the table if it is missing, adds the columns that a table created by an earlier
version lacks, and does nothing otherwise:

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
whether the table exists and is up to date, and creates or upgrades it if needed. It never creates the table inside an open
transaction: DDL would implicitly commit your transaction on MySQL or abort it on
PostgreSQL. `store()` normally runs inside your transaction, so a missing table then raises
a `LogicException` that tells you to run `somework:cqrs:outbox:setup`. Dates are stored in
UTC, so neither the time zone of the writing process nor daylight saving time changes the
relay order. In practice,
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
| `attempts` | INTEGER, default `0` | No | Attempts to relay the row (counted when an attempt starts) |
| `available_at` | DATETIME_IMMUTABLE | Yes | Earliest time of the next attempt after a failure (`null` = now) |
| `failed_at` | DATETIME_IMMUTABLE | Yes | When the relay gave up on the row (`null` = still relayed) |
| `last_error` | TEXT | Yes | Exception class and message of the last failure |

An index on `(published_at, created_at)`, named `idx_<table>_published_created`, serves the
relay query. `attempts`, `available_at`, `failed_at` and `last_error` were added in 0.5.0;
[upgrade](#upgrading-from-04) a table created by an earlier version.

### Upgrading from 0.4

`store()` keeps working on a table of an earlier version, so deploying the new version does
not break writes. The relay needs the new columns. Add them with one of:

- `bin/console somework:cqrs:outbox:setup` (or `auto_setup: true`, outside a transaction);
- `doctrine:migrations:diff` when `doctrine/orm` is installed (the schema listener includes
  the new columns);
- a migration of your own:

```sql
-- PostgreSQL
ALTER TABLE somework_cqrs_outbox ADD attempts INT DEFAULT 0 NOT NULL;
ALTER TABLE somework_cqrs_outbox ADD available_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
ALTER TABLE somework_cqrs_outbox ADD failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
ALTER TABLE somework_cqrs_outbox ADD last_error TEXT DEFAULT NULL;

-- MySQL / MariaDB
ALTER TABLE somework_cqrs_outbox ADD attempts INT DEFAULT 0 NOT NULL, ADD available_at DATETIME DEFAULT NULL,
    ADD failed_at DATETIME DEFAULT NULL, ADD last_error LONGTEXT DEFAULT NULL;
```

## Relaying

```bash
bin/console somework:cqrs:outbox:relay            # up to 100 messages
bin/console somework:cqrs:outbox:relay --limit=500
```

| Option | Default | Description |
|--------|---------|-------------|
| `--limit`, `-l` | `100` | Maximum number of rows to process (relay or fail) in this run (positive integer). |

For each due row, oldest first (`created_at`, then `id`), the relay:

1. counts the attempt and postpones the row until its next retry time, so that a process
   that dies during the attempt (a PHP fatal error, running out of memory, a killed worker)
   does not start the next run with the same row again;
2. decodes the row with the outbox serializer;
3. adds a `TransportNamesStamp` with the stored transport name, if one was stored;
4. dispatches the envelope through the bus of the message type: `buses.command_async` (else
   `buses.command`) for commands, `buses.event_async` (else `buses.event`) for events,
   `buses.query` for queries, the default bus for anything else;
5. marks the row as published.

What happens in special cases:

- **Failing rows are retried later.** If decoding, dispatching or marking a row fails, the
  relay records the failure (`attempts`, `last_error`) and postpones the row: the next
  attempt waits 1 minute, then 2, 4, 8 … minutes, at most 1 hour. It prints
  `Failed to relay message "<id>" (attempt 1 of 10, next attempt after <time>): <reason>`
  and moves on, so a failing row never blocks the rows behind it. When any row failed, the
  command exits with code `1`. See [Failures](#failures) for what happens after the last
  attempt.
- **An outage stops the run.** After 5 consecutive rows could not be sent (or marked as
  published), the relay prints `Stopping after 5 consecutive failures to send messages.` and
  exits with `1` instead of walking the whole backlog. Rows that cannot be decoded do not
  count towards this limit. If the storage itself fails (the database is down), the run
  stops right away with `Stopping: …` and exit code `1`.
- **An outage never gives up a row.** A send failure only gives up a row after its last
  attempt if another message was sent in the same run. While nothing can be sent, the row is
  kept and tried again an hour later. Rows that failed during an outage wait at most one
  hour after it ends.
- **Messages handled inline trigger a warning.** If no transport received a message (no
  stored transport name and no routing), the default bus handles it synchronously inside the
  relay process on the bus of its type. The relay prints a warning and still marks the row as
  published. Store a transport name or add routing to avoid this.
- **Only one relay runs at a time.** When `symfony/lock` is installed, the command takes a
  lock named after the application, the connection and the table, and extends it after
  every row; if the lock is lost, the run stops with exit code `1`. The application part is
  `framework.cache.prefix_seed` when you set it, the project directory otherwise (Symfony's
  default seed is not used: it differs between environments and debug modes). If every
  release is deployed to a new directory, set `prefix_seed` to a stable value (Symfony
  recommends this anyway), so the relays of the old and the new release share the lock. The lock comes from the application's `lock.factory` service.
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
| `0` | All selected rows were relayed, no row was due, or another relay holds the lock |
| `1` | At least one row failed, the transport looked unavailable, the storage failed (e.g. the database is down), or the lock could not be acquired or was lost |
| `2` | Invalid `--limit` |

The relay handles at most `--limit` rows per run and then exits. Run it on a schedule, for
example from cron:

```bash
* * * * * /path/to/project/bin/console somework:cqrs:outbox:relay --limit=500
```

## Failures

A row that still fails after `max_attempts` attempts (default 10, about 4 hours of retries)
is given up: the relay prints `Gave up on message "<id>" after 10 attempt(s): <reason>`, sets
`failed_at` and never selects the row again. It stays in the table for inspection. List the
given-up rows and hand them back to the relay once the cause is fixed:

```bash
bin/console somework:cqrs:outbox:failed                    # id, transport, dates, attempts, last error
bin/console somework:cqrs:outbox:failed --limit=200
bin/console somework:cqrs:outbox:failed --requeue          # every given-up row
bin/console somework:cqrs:outbox:failed --requeue <id> <id>
```

Requeued rows start again with `attempts = 0`. A transport outage does not give rows up (see
[Relaying](#relaying)); a row that crashed the relay process is given up after its last
attempt with the error `The relay stopped during this attempt …`. `purge` never deletes given-up rows; delete them
with SQL (`DELETE FROM somework_cqrs_outbox WHERE failed_at IS NOT NULL`) if you do not want
to relay them.

## Monitoring

- `somework:cqrs:health` includes an outbox check: a warning when the relay gave up on rows,
  and when the oldest due row has waited more than 10 minutes (the relay is not running or
  does not keep up); critical when the table cannot be read.
- The relay logs failed attempts (warning), given-up rows and stopped runs (error) to the
  application's `logger` service, besides printing them.
- Alert on the relay's exit code `1`.

## Purging published rows

Published rows stay in the table until you purge them:

```bash
bin/console somework:cqrs:outbox:purge                        # published more than 7 days ago
bin/console somework:cqrs:outbox:purge --older-than="12 hours"
```

Rows are deleted in batches of 1000, so purging a large backlog does not hold one long lock.
`--older-than` takes a number and a unit (`second`, `minute`, `hour`, `day`, `week`, `month` or
`year`, singular or plural), such as `7 days`, `12 hours` or `1 month`, with at most 6 digits.
The default is `7 days`. Only rows whose `published_at` is older than that are deleted.
Unpublished rows, including given-up ones, are never deleted. An invalid value exits with code `2`. Schedule the purge, for example
daily.

## Delivery guarantees

- **Atomic write.** The row exists only if your transaction commits.
- **At-least-once delivery.** The relay marks a row as published *after* dispatching it. A
  crash between the two steps, a failed `markPublished()`, or two relays without a shared
  lock can send the same message twice. **Consumers must be idempotent**, for example by
  recording processed message ids under a unique constraint. The bundle's
  [idempotency bridge](idempotency.md) does not cover this case: relayed messages do not
  pass through the CQRS stamp pipeline.
- **Order.** Rows are relayed in the order they were stored. A row that fails is postponed,
  so later rows overtake it. Several workers consuming the
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
     * Returns the messages that are due, oldest first: unpublished, not given up, and past the
     * retry time of their last failed attempt.
     *
     * @return list<OutboxMessage>
     */
    public function fetchUnpublished(int $limit): array;

    /**
     * Marks a message as published. Marking an already published message is a no-op.
     *
     * @throws \RuntimeException when the message does not exist
     */
    public function markPublished(string $id): void;

    /**
     * Records an attempt to publish a message: the number of attempts so far, its error, and when
     * to try again.
     *
     * The relay records each attempt before it sends the message, with an error saying that the
     * attempt did not finish, so an attempt that kills the process still counts; it records the
     * actual error when the attempt fails. A message must not be returned by {@see fetchUnpublished()}
     * before $retryAt; with $retryAt null it is given up and never returned again. Recording an
     * attempt of an already published message is a no-op.
     *
     * @param int $attempts The number of attempts, including this one
     *
     * @throws \RuntimeException when the message does not exist
     */
    public function markFailed(string $id, int $attempts, string $error, ?DateTimeImmutable $retryAt): void;

    /**
     * Deletes messages published before the given date and returns how many were deleted.
     */
    public function purgePublished(DateTimeImmutable $publishedBefore): int;
}
```

An implementation must meet these rules:

- `store()` must write through the same transaction as your business data. Otherwise the
  outbox guarantees nothing.
- `fetchUnpublished()` returns due messages only (unpublished, not given up, retry time
  passed), in a stable oldest-first order.
- `markFailed()` stores the given number of attempts, the error and the retry time as they
  are; it is called before every attempt and again when the attempt fails. The relay decides
  when to give up (`$retryAt` null) from the `attempts` of the message, so return it.
- Rebuild each message with
  `new OutboxMessage(string $id, string $body, string $headers, DateTimeImmutable $createdAt, ?string $transportName = null, int $attempts = 0)`.
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
- `somework:cqrs:outbox:setup` and `somework:cqrs:outbox:failed` only work with
  `DbalOutboxStorage`; with another storage they exit with `1`.

## Limitations

- **Polling only.** Messages leave the outbox when the relay runs. Change data capture is
  not supported.
- **One database.** The outbox table must be reachable through the same connection and
  transaction as your business data. Distributed transactions are not supported.
- **Given-up rows wait for you.** Rows the relay gave up on stay in the table until you
  requeue or delete them. Once a row is relayed, Messenger's retry and failure transports
  take over.
