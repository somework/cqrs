# Transactional outbox

Suppose a handler commits a database change and then sends a message to a broker. It can
fail between the two steps: the change is saved and the message is lost, or the message
goes out for a change that was rolled back. With the outbox, you store the message in a
database table **in the same transaction** as the business change. The
`somework:cqrs:outbox:relay` command later sends the stored messages to Messenger.

!!! note "Stability"
    `OutboxWriter`, `OutboxStorage`, `OutboxMessage` and `DbalOutboxStorage` are part of the
    public API (`@api`). Write with `OutboxWriter`, or type-hint the `OutboxStorage` interface;
    `DbalOutboxStorage` is public for `addTableToSchema()` in migrations and for the setup and
    failed-message tools.

## How it works

1. Inside your database transaction, you write the business data and store the message with
   `OutboxWriter::store()`.
2. The transaction commits. The message row is saved only if the business change is.
3. `somework:cqrs:outbox:relay` reads due rows, transport by transport, decodes each one, and
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

The Flex recipe of DoctrineBundle writes a `doctrine.orm` section. Without doctrine/orm the
container then fails with `The doctrine/orm package is required when the doctrine.orm config
is set`: remove that section, or install the ORM (`composer require symfony/orm-pack`).

## Quick start

1. Enable the outbox (`somework_cqrs.outbox.enabled: true`, see [Configuration](#configuration)).
2. Create the table: `bin/console somework:cqrs:outbox:setup`, or a Doctrine migration.
3. Store messages with `OutboxWriter::store()` inside your transaction (see
   [Writing to the outbox](#writing-to-the-outbox)).
4. Run `bin/console somework:cqrs:outbox:relay` every minute (see [Relaying](#relaying)) and
   purge published rows every night (see [Purging published rows](#purging-published-rows)).
5. Watch `bin/console somework:cqrs:health` (see [Monitoring](#monitoring)).

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
        max_attempts: 10
```

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `false` | Registers the outbox storage and the four console commands. It decides which services exist, so it must be a plain boolean, not an `%env()%` value. |
| `table_name` | `somework_cqrs_outbox` | Name of the outbox table: letters, digits and underscores, optionally `schema.table` (on MySQL and MariaDB `database.table`: without a database selected on the connection, its `dbname`, the automatic setup is skipped, and the Doctrine schema listener only adds a table of the connection's database to generated migrations). A word reserved in MySQL, MariaDB, PostgreSQL or SQLite (such as `order` or `user`) is a configuration error, because the queries do not quote the name. |
| `connection` | `default` | DBAL connection name. The storage uses the service `doctrine.dbal.<name>_connection`. Use the connection that holds your business data, otherwise `store()` is not part of the business transaction. |
| `serializer` | `messenger.default_serializer` | Messenger serializer service id. It is exposed as the alias `somework_cqrs.outbox.serializer` for code that writes to the outbox, and the relay uses it to decode rows. |
| `auto_setup` | `true` | Creates the table on first use if it is missing, or adds the columns a table of an earlier version lacks, but never inside an open transaction. Indexes are left to `somework:cqrs:outbox:setup`. Set it to `false` when migrations manage the table. |
| `max_attempts` | `10` | Attempts after which the relay gives up on a row that cannot be decoded or sent (at least 1). A row whose transport fails gets three times as many. See [Failures](#failures). |

## Writing to the outbox

Inject `OutboxWriter` and call `store()` inside your transaction:

```php
<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Event\OrderPlaced;
use Doctrine\DBAL\Connection;
use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;

#[AsCommandHandler(command: PlaceOrder::class)]
final class PlaceOrderHandler
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OutboxWriter $outbox,
    ) {
    }

    public function __invoke(PlaceOrder $command): mixed
    {
        $this->connection->transactional(function () use ($command): void {
            $this->connection->insert('orders', [
                'id' => $command->orderId,
                'customer_id' => $command->customerId,
            ]);

            // Stored while this handler runs, the event continues the flow of PlaceOrder:
            // same correlation id, PlaceOrder as its cause (pass a MessageMetadataStamp to override).
            $this->outbox->store(new OrderPlaced($command->orderId));
        });

        return null;
    }
}
```

`OutboxWriter::store(object $message, ?string $transportName = null, StampInterface ...$stamps)`
returns the stored rows. The main points:

- **Use the same connection.** `Connection` must be the connection named in
  `outbox.connection`. With Doctrine ORM, call `store()` inside
  `EntityManagerInterface::wrapInTransaction()`. The entity manager of that connection
  shares the same `Connection` instance.
- **The transport.** Without a transport name, the writer sends the message where an
  asynchronous dispatch through the CQRS buses would: the transports of
  `transports.command_async` or `transports.event_async` for the message, or the one of
  `#[Asynchronous(transport: ...)]`. It stores one row per transport, so a failing transport
  is retried alone. When none is configured, the row follows `framework.messenger.routing`
  when it is relayed. A transport name you pass wins. The relay sends the message there with
  Messenger's `TransportNamesStamp`.
- **Only your stamps.** The rest of the stamp pipeline does not run: the bundle adds no
  metadata, retry or serializer stamps. Pass the stamps you need; they are serialized with
  the message.
- **The bus is chosen for you.** The relay dispatches commands on `buses.command_async`
  (or `buses.command`), events on `buses.event_async` (or `buses.event`), queries on
  `buses.query`, and anything else on the default bus. That bus adds its `BusNameStamp`, so
  the worker hands the message to the bus where its handlers are registered. A
  `BusNameStamp` you store yourself is kept.

Rows get a time-ordered UUIDv7 id: rows stored in the same millisecond by one process keep
their order.

Without the writer, build the row yourself with
`OutboxMessage::fromEnvelope(Envelope $envelope, SerializerInterface $serializer, ?string $transportName = null, ?DateTimeImmutable $createdAt = null)`
and pass it to `OutboxStorage::store()`. Encode it with the `somework_cqrs.outbox.serializer`
service (the `outbox.serializer` option, by default `messenger.default_serializer`), which the
relay decodes rows with, not with a transport's serializer.

## Creating the table

The table has to exist before the first `store()`. There are three ways to create it:

**Setup command.** Run it once per environment, for example in your deployment script. It
creates the table if it is missing, adds the columns and indexes that a table created by an earlier
version lacks (see [Upgrading from 0.4](#upgrading-from-04)), and does nothing otherwise:

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
whether the table exists and has the columns of this version (without locking anything). If
the table is missing, it creates it; if it lacks the columns of this version (a table of 0.4),
it adds them. This only happens outside a transaction: inside one, storing a message in a
table of 0.4 fails (and your transaction is rolled back) until the columns exist, which is why
the setup command runs before the deployment (the health check reports it as critical). The
relay and the `failed` command add the columns they need too; indexes are left to the setup
command.
Processes that start at the same time wait for each other (at most 30 seconds, less once
another one has added the columns). Adding the columns does not wait in the lock queue of the
table, where the writes would queue behind it: PostgreSQL tries `LOCK TABLE … NOWAIT` and
MariaDB `ALTER TABLE … NOWAIT` for up to 1 second. An autovacuum of the table gives way to a
waiting lock after `deadlock_timeout`, so while only autovacuum holds the table, PostgreSQL waits
that long (writes wait too), unless it prevents a wraparound, which does not give way; this needs
a role that sees the sessions of other roles (`pg_read_all_stats`), otherwise the upgrade fails
while autovacuum runs. MySQL and MariaDB only add the columns when that takes no time
(`ALGORITHM=INSTANT`; a compressed table, for example, would be rebuilt). MySQL cannot do
that: it does not try while any transaction of the server has been open for more than a
second (it does not tell which tables a transaction holds; this needs the `PROCESS`
privilege, without it each attempt holds up the writes to the table for up to 1 second).
Until the columns exist, every relay run fails with `could not be changed: another session …
kept it locked` (MySQL: `is not changed while a transaction of the database server has been
open`): run the setup command. On PostgreSQL this runs in one transaction, so it is safe behind a
pooler in transaction mode (PgBouncer). It never builds or drops an index, which can take long on
a big table: the relay and the health check warn until `somework:cqrs:outbox:setup` has done
it. Until then (the relay checks for the index every 10 seconds, also with `auto_setup: false`)
it fetches with one query along the index of 0.4, in the order the rows were stored: the
transports do not take turns, and rows that are not due (retries, given-up rows, paused
transports) are read past, which slows fetches when many of them are ahead. The health
check reads all pending rows, which takes seconds with a large backlog, and warns while the table
lacks the columns, and turns critical once messages have waited there for more than 10 minutes
(the relay cannot send them until the setup command has run). It never creates the table inside an open
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
| `attempts` | INTEGER, default `0` | No | Attempts to relay the row (counted when the relay claims it) |
| `available_at` | DATETIME_IMMUTABLE | Yes | Earliest time of the next attempt (`null` = never attempted, or requeued) |
| `failed_at` | DATETIME_IMMUTABLE | Yes | When the relay gave up on the row (`null` = still relayed) |
| `last_error` | TEXT | Yes | Exception class and message of the last failure |
| `claim_token` | VARCHAR(32) | Yes | The relay run that claimed the row for an attempt it has not finished |
| `claimed_at` | DATETIME_IMMUTABLE | Yes | When that claim was made (set on a due row: its last attempt was interrupted) |
| `signature` | VARCHAR(64) | Yes | Signature of the id, body and headers (see [Security](#security)) |

An index on `(published_at, failed_at, transport_name, available_at, created_at, id)`, named
`idx_<table>_pending` (`idx_<hash>_pending` for long table names), serves the relay, the
purge and the counts of the health check; `idx_<table>_claimed` on `claimed_at` finds
unfinished claims. `attempts`, `available_at`, `failed_at`, `last_error`, `claim_token`,
`claimed_at`, `signature` and these indexes were added in 0.5.0;
the index replaces `idx_<table>_published_created` of 0.4. [Upgrade](#upgrading-from-04) a table created
by an earlier version.

### Upgrading from 0.4

Writes need the new columns (`store()` writes the signature), so upgrade the table before the
new version takes traffic. Outside a transaction, the automatic setup adds the columns before
the first write (it gives up after 1 second behind a transaction that holds the table); inside
one, `store()` fails with `The outbox table "…" lacks columns this version of the bundle needs
(…). Upgrade it with "bin/console somework:cqrs:outbox:setup" or a Doctrine migration.` Stop
the relays of the old version before the new ones start: an old relay ignores the new columns
(retry times, given-up rows, claims). The relay also needs the new index to stay fast. Add the
columns and the index with one of:

- `bin/console somework:cqrs:outbox:setup`, over a direct database connection (setups that
  start at the same time wait for each other, except while one builds the index on
  PostgreSQL: then the others stop at once with `Another process is building the index`, see
  below; the wait uses a database lock held by the session, which
  PgBouncer in transaction mode would hand to another client: on PostgreSQL the command
  usually notices it and refuses (releasing the lock), or fails saying that the lock stayed
  with another server connection; under light load it may not notice, so do not rely on it). While
  another setup holds the lock, it says so and waits for it. A signal (e.g. a deploy job that is terminated) stops it with the
  exit code `128 + signal`, once the running statement returns (on a network that drops the
  connection silently, only once libpq notices: set TCP keepalives, e.g. `keepalives_idle`, on the
  connection); the next setup continues. On
  PostgreSQL it builds the index with `CREATE INDEX CONCURRENTLY` (without the role's
  `statement_timeout`), so writes go on while it runs; it waits for transactions that started
  before (e.g. a `pg_dump`). It rebuilds an index that an interrupted build left invalid, and
  stops with `Another process is building the index` while one is being built (e.g. by your
  migration, or by the server process of a setup that was killed). The old index is dropped only once the new one exists. Changing the table needs
  a moment without open transactions on it: adding
  the columns (and, on MySQL and MariaDB, the index) waits at most 5 seconds for them, then
  fails with `could not be changed: another session … kept it locked` instead of blocking every
  write behind it; run the setup again when the table is less busy. On MySQL and MariaDB the
  index is built online, but the build needs that moment at its end too: if a transaction
  (e.g. a dump) holds the table then, the work of the build is lost. With `auto_setup: true`,
  the first relay run adds the columns (it does not queue behind other sessions: see
  [Creating the table](#creating-the-table) for autovacuum, MySQL and tables that MySQL or
  MariaDB would rebuild), so the relay usually works before the setup command has run, only slower;
- `doctrine:migrations:diff` when `doctrine/orm` is installed (the schema listener includes
  the new columns and index);
- a migration of your own:

```sql
-- PostgreSQL
ALTER TABLE somework_cqrs_outbox ADD attempts INT DEFAULT 0 NOT NULL;
ALTER TABLE somework_cqrs_outbox ADD available_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
ALTER TABLE somework_cqrs_outbox ADD failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
ALTER TABLE somework_cqrs_outbox ADD last_error TEXT DEFAULT NULL;
ALTER TABLE somework_cqrs_outbox ADD claim_token VARCHAR(32) DEFAULT NULL;
ALTER TABLE somework_cqrs_outbox ADD claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
ALTER TABLE somework_cqrs_outbox ADD signature VARCHAR(64) DEFAULT NULL;
CREATE INDEX CONCURRENTLY idx_somework_cqrs_outbox_pending ON somework_cqrs_outbox (published_at, failed_at, transport_name, available_at, created_at, id);
CREATE INDEX CONCURRENTLY idx_somework_cqrs_outbox_claimed ON somework_cqrs_outbox (claimed_at);
DROP INDEX CONCURRENTLY idx_somework_cqrs_outbox_published_created;

-- MySQL / MariaDB
ALTER TABLE somework_cqrs_outbox ADD attempts INT DEFAULT 0 NOT NULL, ADD available_at DATETIME DEFAULT NULL,
    ADD failed_at DATETIME DEFAULT NULL, ADD last_error LONGTEXT DEFAULT NULL, ADD claim_token VARCHAR(32) DEFAULT NULL,
    ADD claimed_at DATETIME DEFAULT NULL, ADD signature VARCHAR(64) DEFAULT NULL;
CREATE INDEX idx_somework_cqrs_outbox_pending ON somework_cqrs_outbox (published_at, failed_at, transport_name, available_at, created_at, id);
CREATE INDEX idx_somework_cqrs_outbox_claimed ON somework_cqrs_outbox (claimed_at);
DROP INDEX idx_somework_cqrs_outbox_published_created ON somework_cqrs_outbox;
```

`CREATE INDEX CONCURRENTLY` cannot run inside a transaction: Doctrine migrations need
`isTransactional()` to return `false` for it. 0.4 had no purge command, so its table may hold
every message ever relayed. Building the indexes then takes a while, and a migration generated
by `doctrine:migrations:diff` uses a plain `CREATE INDEX`, which blocks writes on PostgreSQL
until it is done. It also drops the old index before it creates the new one, so the relay has
no index while the migration runs; put the `DROP INDEX` last. Purge the published rows first
(`somework:cqrs:outbox:purge` works on the 0.4 table and does not change it), or use the
statements above.

## Relaying

```bash
bin/console somework:cqrs:outbox:relay            # up to 100 messages
bin/console somework:cqrs:outbox:relay --limit=500
```

| Option | Default | Description |
|--------|---------|-------------|
| `--limit`, `-l` | `100` | Maximum number of rows to process (relay or fail) in this run (positive integer). |

The relay fetches up to 50 due rows at a time and:

1. claims them, in one statement per group of rows with the same number of attempts: each
   claim counts the attempt, marks the row with the token of the run and the time, and
   postpones the row until its next retry time, provided that no other relay claimed the row
   since it was read. A process that dies during the batch (a PHP fatal error, running out of
   memory, a killed worker) therefore does not start the next run with the same rows, and a
   relay that overlaps this one skips them;
2. for each claimed row, decodes it with the outbox serializer;
3. adds a `TransportNamesStamp` with the stored transport name, if one was stored;
4. dispatches the envelope through the bus of the message type: `buses.command_async` (else
   `buses.command`) for commands, `buses.event_async` (else `buses.event`) for events,
   `buses.query` for queries, the default bus for anything else;
5. marks the sent rows as published, every 2 seconds and at the end of the run;
6. renews the claims of the batch every 20 seconds, so a slow batch keeps its rows, and skips a
   row whose claim another relay took over meanwhile;
7. releases the claims of the rows it did not attempt (the run ended, its storage failed, or
   their transport was paused): their attempt is not counted.

A due row that is still claimed was being sent by a relay that died (its claim was not
finished before the retry time). The relay claims and sends such a row on its own, before the
others, so that if the row kills the process again, only that row is blamed.

The transports take turns, the one whose next row has waited longest first (rows stored
without a transport name count as one transport), so the backlog of one transport, for example
after an outage, does not hold up the others: each fetch orders the transports by the age of
their next row and then takes one row of each in turn. (When more transports have a backlog
than a fetch has rows, a transport whose next row is newer waits until the older ones are
relayed.) Within
a transport the relay takes the new rows first (never attempted, or requeued), in the order
they were stored, then the rows that failed before and whose retry time has passed, in the order
of their retry time. Rows that keep failing therefore do not hold up new rows. A relay that
cannot keep up with the new rows of a transport retries its failed rows only once it catches
up; the health check reports both (see [Monitoring](#monitoring)).

What happens in special cases:

- **Failing rows are retried later.** If a row cannot be decoded or dispatched, the relay
  records the failure (`attempts`, `last_error`) and postpones the row: the next attempt
  waits 1 minute, then 2, 4, 8 … minutes, at most 1 hour. It prints
  `Failed to relay message "<id>" (attempt 1 of 10, next attempt after <time>): <reason>`
  and moves on to the next row. When any row failed, the command exits with code `1`. See
  [Failures](#failures) for what happens after the last attempt.
- **A failing transport is paused, the other transports go on.** A send that fails with
  Messenger's `TransportException` (the broker cannot be reached, or it rejects the message)
  counts against three times `max_attempts`: 30 attempts by default, about a day of retries,
  so a broker outage of some hours gives no row up. After 3 such failures in a row for one
  transport, the relay prints
  `Transport "<name>" failed 3 times in a row; its other messages wait for the next run.`
  and skips the rows of that transport for the rest of the run instead of walking its whole
  backlog. The rows of the other transports are relayed as usual. As new rows come first,
  3 new rows are enough to detect an outage, and the attempts of older rows are not used up.
  A transport that accepted a message earlier in the run is up: single messages it rejects
  (e.g. too large) do not pause it before 10 failures in a row, or 3 failures in a row that
  took more than 10 seconds (a transport that went down during the run and makes every send
  wait for a timeout). A burst of new rows that the broker rejects before any row of
  the run went through is only told apart from an outage by trying them: each run tries 3 of
  them, so the burst delays the other rows of that transport, by one run per 3 rejected rows.
  When most of a transport's traffic is rejected (say 9 rows out of 10), the other rows keep
  waiting behind such bursts: fix what the broker rejects (the given-up rows show the error).
  Rows stored without a transport name share one such counter (`Messages without a transport
  name failed to be sent 3 times in a row …`): when one of the transports they are routed to
  is down, the others' rows may wait for the next run too. Store the transport name to keep
  transports apart. A handler that ran inline and failed counts against `max_attempts`, even
  when it failed to send another message: its side effects happened.
- **A storage failure stops the run.** If the database is down, or sent rows cannot be
  marked as published, the run stops right away with `Stopping: …` and exit code `1`. Rows
  that were sent but not marked (at most those of the last 2 seconds) are sent again later
  (see [Delivery guarantees](#delivery-guarantees)).
- **Messages handled inline trigger a warning.** If no transport received a message (no
  stored transport name and no routing), the bus of its type handles it synchronously inside
  the relay process. The relay prints and logs a warning and still marks the row as
  published. Store a transport name or add routing to avoid this.
- **Messages that go nowhere trigger a warning.** When a message is neither sent nor handled,
  for example because Messenger's deduplication dropped it as a duplicate, or because it is
  an event without handlers or routing, the relay prints and logs `Message "<id>" (<class>)
  was neither sent to a transport nor handled …` and marks the row as published.
- **A retry dropped by the deduplication is a failed attempt.** A row that carries a
  `DeduplicateStamp` takes Messenger's deduplication lock when it is sent, and the lock stays
  held until a worker handled the message or its TTL (300 seconds by default) expires. An
  earlier attempt of the same row may hold it: one that sent the message but died before the
  row was marked as published, or one whose send failed without releasing it (the bundle's
  idempotency bridge releases it). The relay cannot tell these apart, and prefers a duplicate
  to a lost message: it marks only a *first* attempt that the deduplication dropped as
  published (a duplicate of another row); a dropped retry fails with `Messenger's
  deduplication dropped this retry …` and is retried after the backoff, when the lock has
  usually expired. A lock without a TTL never expires: release it, or the relay gives the row
  up after `max_attempts`. Release the lock (or wait for its TTL) before you requeue such a
  row: a requeued row starts again at its first attempt. `OutboxWriter` scopes the key to the
  transport of the row (`<key>@<transport>`), so the rows of a message stored for several
  transports, at once or one by one, do not drop each other; a row that follows the Messenger
  routing keeps the key. Rows you store with `OutboxStorage::store()` yourself need distinct
  keys per transport. A scoped key does not match the same key dispatched directly on a bus,
  so a message dispatched both ways is not deduplicated across the two.
- **SIGTERM and SIGINT stop the run after the current row.** With the `pcntl` extension, the
  relay finishes the row it is working on, starts no other, marks the sent rows as published,
  releases the claims of the others, prints `Stopped by signal <number>
  after <count> message(s); the remaining messages wait for the next run.`, releases the lock
  and exits with `1`. A second signal stops it at once. PHP handles signals between
  operations: a send blocked on the network is only interrupted by the transport's own
  timeout, so configure timeouts on your transports (and a grace period longer than them).
  While the relay waits for another process to add the columns to the table (at most 30
  seconds, on upgrade day), the first signal takes effect after that wait.
- **Only one relay runs at a time.** When `symfony/lock` is installed, the command takes a
  lock named after the application, the connection and the table. The lock expires after 60
  seconds, and the relay extends it every 10 seconds between rows; if the lock is lost, the run
  stops with exit code `1`. The application part is
  `framework.cache.prefix_seed` when you set it, the project directory otherwise (Symfony's
  default seed is not used: it differs between environments and debug modes). If every
  release is deployed to a new directory, set `prefix_seed` to a stable value (Symfony
  recommends this anyway), so the relays of the old and the new release share the lock. The lock comes from the application's `lock.factory` service.
  FrameworkBundle registers that service when `framework.lock` is enabled, which is the
  default once `symfony/lock` is installed. Without that service, Symfony's
  `LockableTrait` creates a local semaphore or flock store. The default stores only guard
  one host, so configure a shared store in `framework.lock` (Redis, a database, …) when
  cron runs the relay on several servers. A second relay that finds the lock taken prints
  `Another outbox relay is already running.` and exits with `0`. After a PHP fatal error the
  relay still releases the lock (in a shutdown function). A relay killed without cleanup
  (SIGKILL, the OOM killer, a container stopped after its grace period) cannot: the next runs
  exit with `0` without relaying until the lock expires, at most 60 seconds later.
- **Relays that overlap anyway skip each other's rows.** Without `symfony/lock`, or with a
  lock store that only guards one host, two relays can run at the same time. The claim keeps
  them from sending the same row twice: a relay that finds a row claimed by the other one
  skips it and prints `Skipped <n> message(s) that another relay claimed first.` The claims of
  a batch are renewed every 20 seconds, between sends. A row can therefore be sent twice when a
  single send takes longer than the claims last after the last renewal (at least 40 seconds on
  the first attempt: the claim holds 1 minute) and the other relay claims them meanwhile: the
  slow row itself, and the rows sent in the 2 seconds before it, which are not marked as
  published yet. Claims are computed with the clock of the relay host: keep the clocks of the
  relay hosts synchronised (NTP). The relay lock is also only extended between sends, so a
  single send that outlasts its TTL (60 seconds) lets another relay start; the claims keep it
  from sending the rows of the slow relay's batch until they run out.

| Exit code | Meaning |
|-----------|---------|
| `0` | All selected rows were relayed, no row was due, or another relay holds the lock |
| `1` | At least one row failed (which includes a paused transport), the storage failed (e.g. the database is down), a signal stopped the run, or the lock could not be acquired or was lost |
| `2` | Invalid `--limit` |

**Throughput.** Each fetch lists the transports with pending rows once per run (again when a
fetch comes back short, or after 10 seconds), reads as many rows of each transport as the batch
of 50 needs, and reads up to 50 transports in one statement (`UNION ALL`). Relaying 20 000
rows took (PHP 8.4, local PostgreSQL 16 and MariaDB 10.11, a bus that only records the
messages) about 2.5 s with 1 transport, 6 s with 10 and 20 s with 100; with a simulated network
round trip of 0.5 ms per statement, reading the transports together is 1.5 times (10
transports) to 2 times (100 transports) faster than reading them one by one. Many transports
cost more statements per row: prefer a few transports with many rows each.

The relay handles at most `--limit` rows per run and then exits. Run it on a schedule, for
example from cron:

```bash
* * * * * /path/to/project/bin/console somework:cqrs:outbox:relay --limit=500
```

## Failures

A row that still fails after `max_attempts` attempts (default 10, about 4 hours of retries),
or after three times as many when its transport keeps failing (about a day), is given up: the relay prints `Gave up on message "<id>" after 10 attempt(s): <reason>`, sets
`failed_at` and never selects the row again. It stays in the table for inspection. List the
given-up rows and hand them back to the relay once the cause is fixed:

```bash
bin/console somework:cqrs:outbox:failed                    # id, transport, dates, attempts, last error
bin/console somework:cqrs:outbox:failed --limit=200
bin/console somework:cqrs:outbox:failed --requeue          # every given-up row
bin/console somework:cqrs:outbox:failed --requeue <id> <id>
bin/console somework:cqrs:outbox:failed --requeue --transport=async <id>   # and send it to another transport
```

Requeued rows start again with `attempts = 0` and no `last_error`.

A row stored for a transport that does not exist (a typo, a renamed transport) is given up on
its first run, without an attempt: `The transport "<name>" does not exist (is the row from another application sharing this table?). Fix the code that
stores it, then run "somework:cqrs:outbox:failed --requeue --transport=<name> <id>".`

When the relay process dies during an attempt (a PHP fatal error, running out of memory, a
killed process, a lost database connection), the row keeps its claim (`claimed_at`) and the
error of the attempt before. It is tried again, on its own, after its retry delay; the rows
the process had claimed with it but not attempted yet count that attempt too. As the cause of
an interrupted attempt is unknown (a hanging broker as well as a message that crashes the
process), the larger budget applies: after three times `max_attempts` attempts, the next run
gives the row up without another attempt, because the row may be what kills the process, with
the error `The last attempt did not finish (…); the message may have been sent. Previous
error: <error of the attempt before>`. The message may have reached its transport: check the
consumer before you requeue it. When you lower `max_attempts`, a row
that already had more failed attempts gets one more attempt and, if it fails, is given up with
its real error.

Publishing a row clears its `failed_at`, `last_error` and claim. `purge` never deletes given-up rows; delete them
with SQL (`DELETE FROM somework_cqrs_outbox WHERE failed_at IS NOT NULL`) if you do not want
to relay them.

## Monitoring

- `somework:cqrs:health` includes an outbox check. It warns when the relay gave up on rows;
  when rows failed and wait for another attempt while the oldest of them was stored more than
  10 minutes ago (a transport outage, or rows that cannot be sent); and when the oldest due
  row has waited more than 10 minutes (the relay does not run, does not keep up, or pauses
  their failing transport); when a claim ran out (at the retry time of its attempt) more than
  10 minutes ago without a relay taking it over (a relay died, or hangs on a send without a timeout, and no relay runs
  since); and when the
  table needs `somework:cqrs:outbox:setup` (e.g. its index is missing or invalid). It is
  critical when the table cannot be read, and when the table lacks the columns of this version
  (storing a message inside a transaction fails until the setup has run). The check reads at most 10 000
  rows per count (it reports `more than 10000`) and finds the oldest due row with one probe
  per transport along the index, so it stays cheap on a large backlog.
- The relay logs failed attempts, paused transports, messages handled inline or dropped, a
  table that needs the setup command, and
  runs stopped by a signal (warning), and given-up rows and stopped runs (error) to the
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
- **At-least-once delivery.** The relay marks rows as published *after* dispatching them,
  every 2 seconds. A crash in between (it affects the rows sent in the last 2 seconds), a
  failed `markPublished()`, or a single send that outlasts the claims (at least 40 seconds)
  while another relay runs (it affects that row and the rows sent in the 2 seconds before
  it) can send the same message twice; so does the retry of a row carrying a `DeduplicateStamp`
  after such a crash, once the deduplication lock expired. A message routed
  to several transports is sent to all of them again when one of them fails: store one row
  per transport to avoid that. **Consumers must be idempotent**, for example by
  recording processed message ids under a unique constraint. The bundle's
  [idempotency bridge](idempotency.md) does not cover this case: relayed messages do not
  pass through the CQRS stamp pipeline.
- **Order.** New rows are relayed in the order they were stored. A row that fails is
  postponed, so later rows overtake it, and so do the rows of other transports while its
  transport is paused. Several workers consuming the
  transport can also process messages out of order.
- **Latency.** Messages leave the outbox only when the relay runs. Your schedule sets the
  delay.

## Custom storage

The DBAL storage covers relational databases that DoctrineBundle can connect to. To keep
the outbox somewhere else, implement `SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage`:

```php
<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract\Outbox;

use DateTimeImmutable;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;

/**
 * Persists messages in a transactional outbox for reliable async dispatch.
 *
 * The relay works in claims: it fetches due messages, claims them with a token of its run
 * (counting the attempt before anything is sent, so an attempt that kills the process still
 * counts), sends them, and then marks them published, records their failure, or releases the
 * ones it did not get to. A claim that is never finished leaves claimedAt set: when the message
 * is due again, the next relay knows that the attempt was interrupted.
 *
 * Every message a storage returns must carry the id, body, headers and signature exactly as
 * they were stored.
 */
interface OutboxStorage
{
    /**
     * Stores a message; call it inside the database transaction of the business change.
     */
    public function store(OutboxMessage $message): void;

    /**
     * Returns the messages that are due: neither published nor given up, and either never
     * attempted (or requeued) or past their retry time. The transports take turns, the one whose
     * next message has waited longest first (the messages without a transport name count as one
     * transport); within a transport, the messages never attempted come first, in the order they
     * were stored, then the others, in the order of their retry time.
     *
     * @param list<string|null> $excludedTransports Transports whose messages are skipped; null
     *                                              stands for messages stored without a transport name
     *
     * @return list<OutboxMessage>
     */
    public function fetchUnpublished(int $limit, array $excludedTransports = []): array;

    /**
     * Claims fetched messages for an attempt, atomically per message: a message is claimed only
     * while it is neither published nor given up, and its attempts and transport name are still
     * the fetched ones (so two relays cannot claim the same attempt). A claimed message gets the
     * token, claimedAt (now), one more attempt, and its retry time: $retryAt[<fetched attempts>].
     * It is not due before that time, so an attempt that never finishes is retried after it. A
     * run claims each message at most once (a new run uses a new token).
     *
     * @param list<OutboxMessage>           $messages As fetchUnpublished() returned them
     * @param array<int, DateTimeImmutable> $retryAt  Retry times keyed by the fetched number of attempts
     * @param string                        $token    Identifies the claims of one relay run (not empty)
     *
     * @return list<string> The ids of the claimed messages, in the order of $messages
     */
    public function claim(array $messages, array $retryAt, string $token): array;

    /**
     * Renews the claims of fetched messages that are still claimed with $token: claimedAt becomes
     * now and the retry time $retryAt[<fetched attempts>], so the claims of a long batch do not run
     * out before the relay gets to them.
     *
     * @param list<OutboxMessage>           $messages As fetchUnpublished() returned them
     * @param array<int, DateTimeImmutable> $retryAt  Retry times keyed by the fetched number of attempts
     *
     * @return list<string> The ids still claimed with $token, in the order of $messages
     */
    public function renew(array $messages, array $retryAt, string $token): array;

    /**
     * Undoes the claims of messages the relay did not attempt (it stopped, or paused their
     * transport): their attempts, retry time and claimedAt go back to the fetched values. Messages
     * no longer claimed with $token are left alone.
     *
     * @param list<OutboxMessage> $messages As fetchUnpublished() returned them
     */
    public function release(array $messages, string $token): void;

    /**
     * Marks sent messages as published and clears their claim and failure. Ids that do not exist
     * or are already published are ignored.
     *
     * @param list<string> $ids
     */
    public function markPublished(array $ids): void;

    /**
     * Records the failure of a claimed message and ends its claim: the number of attempts, the
     * error, and the retry time; with $retryAt null the message is given up and never returned by
     * fetchUnpublished() again.
     *
     * @return bool false when the message is no longer claimed with $token (e.g. published, or
     *              claimed by another relay); nothing is recorded then
     */
    public function recordFailure(string $id, string $token, int $attempts, string $error, ?DateTimeImmutable $retryAt): bool;

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
  passed). The transports take turns, the one whose next message has waited longest first;
  within a transport, first the messages never attempted,
  in the order they were stored, then the others, in the order of their retry time. It skips
  the excluded transports. The relay excludes a transport after 3 send failures in a row (after 10, or 3 over at least
  10 seconds, once it accepted a message in the run); a storage that
  ignores the exclusion makes it stop early instead of reaching the other transports.
- `claim()` updates each message in one atomic step (e.g. a conditional `UPDATE`): only while
  it is neither published nor given up, and its attempts and transport name are still the
  fetched ones. It returns the ids it claimed; the relay skips the others. A claimed message is
  not due before the given retry time.
- `renew()` moves `claimedAt` to now and the retry time forward for the messages still claimed
  with the token, and returns their ids: the relay renews the claims of a batch every 20
  seconds, so they do not run out before it gets to them.
- `release()` restores the attempts, the retry time and `claimedAt` of the fetched messages
  that are still claimed with the token. `recordFailure()` only changes a message that is still
  claimed with the token, and ends the claim. `markPublished()` also ends the claim.
- Rebuild each message with
  `new OutboxMessage(string $id, string $body, string $headers, DateTimeImmutable $createdAt, ?string $transportName = null, int $attempts = 0, ?string $lastError = null, ?DateTimeImmutable $claimedAt = null, ?DateTimeImmutable $availableAt = null, ?string $signature = null)`.
  Keep the values exactly as stored, the id, body, headers and signature byte for byte (the
  relay verifies the signature). The relay decides when to give up from `attempts`, and
  recognises an interrupted attempt by `claimedAt`. `$headers` is the JSON string produced by
  `fromEnvelope()`, and `$id` and `$body` must not be empty.

Name your implementation under `outbox.storage` (a service id, or a class name, which the
bundle registers as an autowired service):

```yaml
# config/packages/somework_cqrs.yaml
somework_cqrs:
    outbox:
        enabled: true
        storage: App\Outbox\MongoOutboxStorage
```

The relay and purge commands, `OutboxWriter` and the `OutboxStorage` alias then use it.
doctrine/dbal is not needed, and `table_name`, `connection` and `auto_setup` are ignored:
they only configure the DBAL storage. The relay lock is named after the service id.

The other features need more than `OutboxStorage`. Implement the interfaces of
`SomeWork\CqrsBundle\Contract\Outbox` your storage can support:

| Interface | Methods | Used by |
|---|---|---|
| `OutboxSchema` | `setup(?\Closure $onWait = null): void`, `pendingChanges(): list<string>` | `somework:cqrs:outbox:setup`; the relay and the health check report what `pendingChanges()` returns |
| `FailedOutboxMessages` | `fetchFailed(int $limit, array $ids = []): list<FailedOutboxMessage>`, `requeueFailed(array $ids = [], ?string $transportName = null, ?\Closure $sign = null): int` | `somework:cqrs:outbox:failed` |
| `OutboxMonitoring` | `status(): OutboxStatus` | the outbox check of `somework:cqrs:health` |

`fetchFailed()` returns only the given ids when there are any. When `requeueFailed()` gets
`$sign`, it calls `$sign($message)` with each requeued row as stored (id, body, headers) and
stores the returned string as the row's signature; that is how `--requeue --sign` works.
`FailedOutboxMessage` may carry `messageType` (the serializer's `type` header), `bodyClass` (the
class named in a PHP-serialized body, read without unserializing it) and `digest`
(`FailedOutboxMessage::digest($body, $headers)`), which `--sign` shows to the operator and
checks again before it signs a row.

Without them, `setup` and `failed` exit with `1` and say which interface is missing, and the
health check reports the outbox as not checked. `DbalOutboxStorage` implements all three.

To add behaviour to the storage instead (logging, metrics), decorate it:
`#[AsDecorator('somework_cqrs.outbox.storage')]` on a class that implements `OutboxStorage`
and takes the inner storage. The relay, the purge command and your code then go through the
decorator, while `setup`, `failed`, the health check and the relay's report of pending
changes keep working on the configured storage behind it (`somework_cqrs.outbox.base_storage`,
also `somework_cqrs.outbox.dbal_storage` for the DBAL storage), so the decorator does not have
to implement the capabilities. Your own services get them by type-hinting `OutboxSchema`,
`FailedOutboxMessages` or `OutboxMonitoring`, which autowire to that storage when it implements
them.

## Security

The relay decodes due rows with the outbox serializer and dispatches the message it finds.
With Messenger's default PHP serializer, decoding runs `unserialize()`, which can execute code
through classes your application loads. Whoever can write rows (e.g. through an SQL injection
in your application) could therefore run code in the relay, so the rows are signed.

### Signed rows

With `outbox.signing.enabled` (the default), the storage signs every row it stores with
HMAC-SHA256 (a key derived from `framework.secret`, or from `outbox.signing.secret`) over the
id, the body and the headers, and the relay verifies the signature **before** it decodes the
row. A row without a valid signature is given up at once, without being decoded:
`The message is not signed, so it was not decoded …` or `The signature of the message does not
match …`.

What signing protects, and what it does not:

- It keeps rows the application did not store from reaching `unserialize()` and the handlers:
  an attacker who can write to the table but does not know the secret cannot forge a row.
- It does not stop someone with write access from changing the state of rows: deleting them,
  marking them published or given up, changing `transport_name` (not signed, so that
  `outbox:failed --requeue --transport` works), or copying a signed row under the same id to
  send a message again. Consumers must be idempotent anyway (see
  [Delivery guarantees](#delivery-guarantees)).
- It does not help when the secret leaks: rotate it.

Operating it:

- **Store through the `OutboxStorage` service or `OutboxWriter`.** The signature is added by
  a decorator of `somework_cqrs.outbox.storage`; rows stored by SQL, or through a
  `DbalOutboxStorage` you create yourself, are not signed. (The bundle offers no autowiring
  alias of `DbalOutboxStorage` for that reason.)
- **Rotate the secret** by moving the old one to `outbox.signing.previous_secrets` until the
  rows signed with it are relayed. Rotating `framework.secret` (e.g. `APP_SECRET`) rotates the
  outbox secret too, unless `outbox.signing.secret` is set.
- **Rows of an earlier version** are not signed. During a rolling deployment, instances of 0.4
  keep storing unsigned rows until the last one is replaced, so set
  `outbox.signing.accept_unsigned: true` for the upgrade and remove it once those rows are
  relayed. Meanwhile the relay decodes any unsigned row, forged ones included: keep the window
  short. Rows with a wrong signature are always given up.
- **The secret must not be empty.** With an empty `framework.secret` (e.g. an unset
  `APP_SECRET`), every service that stores outbox rows fails to start: set a secret, or
  `outbox.signing.secret`.
- **A row you checked** (e.g. one stored while signing was disabled) is signed with the current
  secret and handed back to the relay with
  `somework:cqrs:outbox:failed --requeue --sign <id> …`. It shows the rows first: the `type`
  header, the class named in a PHP-serialized body (read as text, never unserialized) and a
  SHA-256 prefix of the body. It refuses a row whose `type` header does not match the class in
  its body, and in an interactive terminal it asks for confirmation. It signs the bodies it
  showed: a row whose body changed in the meantime stops the command. Only sign rows your
  application stored.
- A storage of your own must return the id, body, headers and signature exactly as stored.

`signing.enabled: false` restores the trust model of Messenger's Doctrine transport: the
table is trusted.

### Other measures

- **Keep write access narrow.** Let the application connect with a role that can only read and
  write rows (`SELECT`, `INSERT`, `UPDATE`, `DELETE`); every command of the outbox works with
  it once the table is set up. Run `somework:cqrs:outbox:setup` or your migrations with a role
  that may change the schema, and set `auto_setup: false`.
- **Prefer a serializer that does not create arbitrary PHP objects**, e.g.
  `serializer: messenger.transport.symfony_serializer` (JSON). Messages made of primitives, as
  the bundle recommends, encode without extra normalizers. Rows written with another serializer
  cannot be decoded after the switch: relay them first.
- **Symfony 7.4 or later** refuses unsigned `RunProcessMessage` and `RunCommandMessage`; on
  7.2 and 7.3 a forged row can start a process or a console command through Messenger's own
  handlers when `symfony/process` or `symfony/console` is installed.
- The relay drops the stamps that only describe a dispatch in progress (`ReceivedStamp`,
  `SentStamp`, `HandledStamp` and the other non-sendable stamps). Serializers never write them,
  so only a forged row holds them; a `ReceivedStamp` would make the relay handle the message
  itself instead of sending it.

**Error texts.** The relay stores the message of the exception of a failed attempt in
`last_error`, prints it and logs it, and the OpenTelemetry middleware records exceptions on
spans. Exception messages can contain personal data. Rows the relay gave up on keep their
error until you requeue them or delete them (e.g. with SQL, by `failed_at`), so include them in
your retention policy. Control characters are replaced by spaces in stored errors and in the
output of `somework:cqrs:outbox:failed`.

**Message size.** A fetch reads the bodies of at most 8 MiB of messages (a larger message is
read on its own); the rest waits for the next fetch of the same run. The relay needs a few
times the size of the largest message in memory: decoding and sending copy it.

## Limitations

- **Polling only.** Messages leave the outbox when the relay runs. Change data capture is
  not supported.
- **One database.** The outbox table must be reachable through the same connection and
  transaction as your business data. Distributed transactions are not supported.
- **One table per application.** The relay of an application sends every row of its table:
  a second application (or kernel) sharing the table would have its rows given up as
  unsigned or badly signed (another secret) or for an unknown transport, or sent by the wrong
  relay. Give each application its own `table_name`, schema or database.
- **Long transactions slow the relay on PostgreSQL.** While any transaction holds an old
  snapshot (a long report, `pg_dump` on the primary, an idle-in-transaction session), the
  rows relayed since stay in the index as dead entries, and every fetch walks them: relaying
  gets slower the longer the snapshot is held, until it ends (then autovacuum cleans up).
  Keep long transactions off the primary (run `pg_dump` against a replica), and watch
  `pg_stat_activity` for `idle in transaction` sessions. Larger `--limit` runs suffer less.
- **Given-up rows wait for you.** Rows the relay gave up on stay in the table until you
  requeue or delete them. Once a row is relayed, Messenger's retry and failure transports
  take over.
