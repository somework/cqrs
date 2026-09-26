# Transactional outbox

Suppose a handler commits a database change and then sends a message to a broker. It can
fail between the two steps: the change is saved and the message is lost, or the message
goes out for a change that was rolled back. With the outbox, you store the message in a
database table **in the same transaction** as the business change. The
`somework:cqrs:outbox:relay` command later sends the stored messages to Messenger.

!!! note "Stability"
    `OutboxStorage` and `OutboxMessage`, the types you write with, are part of the public API
    (`@api`). `DbalOutboxStorage` is `@internal`: depend on the `OutboxStorage` interface.

## How it works

1. Inside your database transaction, you write the business data and call
   `OutboxStorage::store()` with an `OutboxMessage` built from a Messenger envelope.
2. The transaction commits. The message row is saved only if the business change is.
3. `somework:cqrs:outbox:relay` reads unpublished rows oldest first, decodes each one, and
   dispatches it through the Messenger bus of its type (see [Relaying](#relaying)). The message
   goes to the transport stored with the row, or follows the Messenger routing when no
   transport was stored. Then the relay marks the row as published.

Writing to the outbox is always explicit. The CQRS buses (`EventBus::dispatch()` and so on)
never write to the outbox.

## When do I need the outbox?

- **Your transport is not your database** (AMQP, Redis, SQS, Kafka…): a message sent from a
  handler is either sent before the commit (and goes out for a change that may still roll back)
  or after it (and is lost when the send fails). The bundle's buses send asynchronous commands
  and events after the handler returned (`dispatch_after_current_bus`, on by default), so a
  failed send leaves the change committed and throws `DeferredDispatchFailedException` from
  `dispatchSync()`; in a worker, the retried command skips the handler that already ran and
  the event is lost with a warning in the Messenger log. Store such messages in the outbox
  instead.
- **Your transport is a Doctrine transport on the same connection** as your business data:
  Messenger inserts the message in the current transaction, so it is already atomic, but only
  when it is sent inside the transaction. Disable `dispatch_after_current_bus` for those
  messages (`somework_cqrs.dispatch_after_current_bus.event.map`), or use the outbox anyway.
- **A message goes to several transports**, some of which may fail: store one row per
  transport, so the relay retries a failed row without sending the message to the other
  transports again.

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

The Flex recipe of DoctrineBundle writes a `doctrine.orm` section. Without doctrine/orm the
container then fails with `The doctrine/orm package is required when the doctrine.orm config
is set`: remove that section, or install the ORM (`composer require symfony/orm-pack`).

## Quick start

1. Enable the outbox (`somework_cqrs.outbox.enabled: true`, see [Configuration](#configuration)).
2. Create the table: `bin/console somework:cqrs:outbox:setup`, or a Doctrine migration (see
   [Creating the table](#creating-the-table)).
3. Inside your transaction, store the messages with `OutboxStorage::store()` (see
   [Writing to the outbox](#writing-to-the-outbox)).
4. Run `bin/console somework:cqrs:outbox:relay` every minute (see [Relaying](#relaying)), and
   purge published rows every night (see [Purging published rows](#purging-published-rows)).
5. Watch the relay's exit code and the rows waiting in the table (see
   [Monitoring](#monitoring)).

The rest of this page explains each step and the cases operations need to know.

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
| `table_name` | `somework_cqrs_outbox` | Name of the outbox table: letters, digits and underscores, optionally `schema.table`; other names are a configuration error. The queries do not quote the name, so do not use a reserved SQL word (such as `order` or `user`). |
| `connection` | `default` | DBAL connection name. The storage uses the service `doctrine.dbal.<name>_connection`. Use the connection that holds your business data, otherwise `store()` is not part of the business transaction. |
| `serializer` | `messenger.default_serializer` | Messenger serializer service id. It is exposed as the alias `somework_cqrs.outbox.serializer` for code that writes to the outbox, and the relay uses it to decode rows. |
| `auto_setup` | `true` | Creates the table on first use if it is missing, but never inside an open transaction. Set it to `false` when migrations manage the table. |

`auto_setup` is read at runtime and may be an `%env()%` value. `table_name`, `connection` and
`serializer` are needed when the container is compiled, so they cannot use environment
variables.

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
  shares the same `Connection` instance. `store()` does not check that a transaction is open:
  called outside one, it saves the row on its own.
- **The stamp pipeline does not run.** Neither writing to the outbox nor relaying goes
  through the CQRS buses, so the bundle adds no metadata, retry, serializer or transport
  stamps. Add the stamps you need to the envelope yourself. They are serialized with the
  message. The example above starts a new correlation; to continue the flow of the handled
  message, pass a `MessageMetadataStamp` with its correlation id and its message id as the
  causation id.
- **Choose the transport.** The third argument is the transport name. The relay sends the
  message there with Messenger's `TransportNamesStamp`, which overrides the routing. With
  `null`, `framework.messenger.routing` decides. The name is not checked when the row is
  stored: a transport that does not exist fails the row on every relay run.
- **The bus is chosen for you.** The relay dispatches commands on `buses.command_async`
  (or `buses.command`), events on `buses.event_async` (or `buses.event`), queries on
  `buses.query`, and anything else on the default bus. That bus adds its `BusNameStamp`, so
  the worker hands the message to the bus where its handlers are registered. A
  `BusNameStamp` you store yourself is kept.
- **The middleware of that bus runs in the relay.** The relay dispatches the stored envelope,
  so the bus middleware (validation, your own middleware) runs in the relay's process, without
  the request, user or tenant of the code that stored it. Middleware that checks the
  dispatching context must let such messages through, for example by recognising a stamp you
  store with them. Check what needs that context before calling `store()`.

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

An index on `(published_at, created_at)`, named `idx_<table>_published_created`
(`idx_<hash>_published_created` when that name would exceed 63 characters), serves the relay
query.

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
3. dispatches the envelope through the bus of the message type: `buses.command_async` (else
   `buses.command`) for commands, `buses.event_async` (else `buses.event`) for events,
   `buses.query` for queries, the default bus for anything else;
4. marks the row as published.

It reads up to `--limit` rows (bodies included) in one query, and reads more only when rows
of that batch failed. A large `--limit` with large messages needs the memory to hold them.

What happens in special cases:

- **Failing rows are skipped and reported.** If decoding, dispatching or marking a row
  fails, the relay prints `Failed to relay message "<id>": <reason>` and moves on. The row
  stays unpublished, so the next run tries it again. When any row failed, the command exits
  with code `1`.
- **An outage stops the run.** After 5 consecutive rows could not be sent (or marked as
  published), the relay prints `Stopping after 5 consecutive failures to send messages.` and
  exits with `1` instead of walking the whole backlog. Rows that cannot be decoded do not
  count towards this limit: they are skipped so they cannot block the queue. When the
  database cannot be read at all, the command fails with the database error.
- **Messages handled inline trigger a warning.** If no transport received a message (no
  stored transport name and no routing), the bus of its type handles it synchronously inside
  the relay process. The relay prints a warning and still marks the row as published. Store a
  transport name or add routing to avoid this. The relay does not reset services (such as
  Doctrine's entity managers) between the messages it handles itself.
- **Only one relay runs at a time.** When `symfony/lock` is installed, the command takes a
  lock named after the project directory, the connection and the table, and extends it after
  every row; if the lock is lost, the run stops with
  `Stopping: the relay lock was lost (…). Another relay may be running.` and exit code `1`.
  The lock comes from the application's `lock.factory` service.
  FrameworkBundle registers that service when `framework.lock` is enabled, which is the
  default once `symfony/lock` is installed. Without that service, Symfony's
  `LockableTrait` creates a local semaphore or flock store. The default stores only guard
  one host, so configure a shared store in `framework.lock` (Redis, a database, …) when
  cron runs the relay on several servers. A second relay that finds the lock taken prints
  `Another outbox relay is already running.` and exits with `0`. Without `symfony/lock`,
  nothing stops two relays from overlapping, and overlapping relays send the same rows
  twice. When every release is deployed to a new directory, the relays of the old and the
  new release use different locks: stop the old relays first.

| Exit code | Meaning |
|-----------|---------|
| `0` | All selected rows were relayed, there was nothing to relay, or another relay holds the lock |
| `1` | At least one row failed, the transport looked unavailable, or the lock was lost |
| `2` | Invalid `--limit` |

The relay handles at most `--limit` rows per run and then exits. Run it on a schedule, for
example from cron:

```bash
* * * * * /path/to/project/bin/console somework:cqrs:outbox:relay --limit=500
```

The rows do not track failed attempts. A row that can never be relayed (for example, its
message class was removed) fails on every run and makes every run exit with `1`. Fix or
delete such rows by hand. See [Production](production.md) for running the relay in a loop
when a minute of latency is too much.

**In development** no scheduled relay usually runs, so stored messages stay in the table until
you run `bin/console somework:cqrs:outbox:relay`. Run it by hand after the request that stored
them, or keep a loop running in a terminal:

```bash
while true; do bin/console somework:cqrs:outbox:relay; sleep 1; done
```

## Purging published rows

Published rows stay in the table until you purge them:

```bash
bin/console somework:cqrs:outbox:purge                        # published more than 7 days ago
bin/console somework:cqrs:outbox:purge --older-than="12 hours"
```

`--older-than` takes a number and a unit (`second`, `minute`, `hour`, `day`, `week`, `month` or
`year`, singular or plural), such as `7 days`, `12 hours` or `1 month`. The default is `7 days`.
Only rows whose `published_at` is older than that are deleted, in a single `DELETE`. Unpublished
rows are never deleted. An invalid value exits with code `2`. Schedule the purge, for example
daily.

## Monitoring

The bundle has no health check for the outbox. Watch it with:

- **The relay's exit code and output.** The relay prints failed rows and warnings to its
  output (it does not write them to the application log): keep the output of the scheduled
  runs. A single run that exits with `1` is not an alert (the row is tried again by the next
  run); runs that keep exiting with `1` are, since a row that always fails makes every run
  fail.
- **The rows that wait.** Count the unpublished rows and the age of the oldest, and alert
  when it grows beyond a few minutes (the relay does not run, cannot keep up, or its
  transport is down):

  ```sql
  SELECT COUNT(*), MIN(created_at) FROM somework_cqrs_outbox WHERE published_at IS NULL;
  ```

  `created_at` is stored in UTC.

## Delivery guarantees

- **Atomic write.** The row exists only if your transaction commits.
- **At-least-once delivery.** The relay marks a row as published *after* dispatching it. A
  crash between the two steps, a failed `markPublished()`, or two relays without a shared
  lock can send the same message twice. A message routed to several transports is sent to all
  of them again when one of them fails: store one row per transport to avoid that.
  **Consumers must be idempotent**, for example by recording processed message ids under a
  unique constraint. The bundle's [idempotency bridge](idempotency.md) does not cover this
  case: relayed messages do not pass through the CQRS stamp pipeline.
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
- `somework:cqrs:outbox:setup` only works with `DbalOutboxStorage`; with another storage it
  exits with `1` and asks you to create the schema yourself.

## Security

The relay decodes unpublished rows with the outbox serializer and dispatches the message it
finds. With Messenger's default PHP serializer, decoding runs `unserialize()`, which can
execute code through classes your application loads. The rows are not signed: as with
Messenger's Doctrine transport, the table is trusted, and whoever can write rows (e.g.
through an SQL injection in your application) could run code in the relay. Limit that risk:

- **Keep write access narrow.** Let the application connect with a role that can only read and
  write rows (`SELECT`, `INSERT`, `UPDATE`, `DELETE`); storing, the relay and the purge command
  work with it once the table exists. Create the table with `somework:cqrs:outbox:setup` or
  your migrations, run with a role that may change the schema, and set `auto_setup: false`.
- **Prefer a serializer that does not unserialize PHP objects**, e.g.
  `serializer: messenger.transport.symfony_serializer` (JSON). It still instantiates the class
  its `type` header names (with the body as constructor arguments). Messages made of
  primitives, as the bundle recommends, encode without extra normalizers. Rows written with
  another serializer cannot be decoded after the switch: relay them first.
- **Symfony 7.4 or later** refuses unsigned `RunProcessMessage` and `RunCommandMessage`; on
  7.2 and 7.3 a forged row can start a process or a console command through Messenger's own
  handlers when `symfony/process` or `symfony/console` is installed.

**Error texts.** The relay prints the message of the exception of a failed row, and the
OpenTelemetry middleware records exceptions on spans. Exception messages can contain personal
data (see [Personal data](production.md#personal-data)).

## Limitations

- **Polling only.** Messages leave the outbox when the relay runs. Change data capture is
  not supported.
- **One database.** The outbox table must be reachable through the same connection and
  transaction as your business data. Distributed transactions are not supported.
- **One table per application.** The relay of an application sends every row of its table:
  the rows of a second application (or kernel) sharing the table would be sent by the wrong
  relay, or fail on every run when their classes do not exist there. Give each application
  its own `table_name`, schema or database.
- **Replicas.** The storage does not switch to the primary before it reads. With Doctrine's
  `PrimaryReadReplicaConnection`, the first read of a relay run can go to a replica, and a
  lagging replica returns rows that were just published, which are then sent again.
- **No dead-lettering of rows.** Rows that always fail stay unpublished until you handle
  them. Once a row is relayed, Messenger's retry and failure transports take over.
