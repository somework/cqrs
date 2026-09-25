# Production deployment guide

This guide covers what to set up when the bundle runs in production: workers,
retries and failed messages, idempotency, the transactional outbox, health
checks, observability and message versioning. Most of it is Symfony Messenger
configuration; the bundle-specific parts are pointed out.

## Workers

Asynchronous commands and events are consumed by regular Messenger workers.
The bundle registers every handler without an explicit `bus` on the sync bus of
its type and on the configured async bus (`buses.command_async`,
`buses.event_async`). A worker therefore finds the handler on the bus the
message was sent from; you do not need `--bus`.

Consume the transports your async messages are sent to: the names under
`transports.command_async` / `transports.event_async`, the transport of
`#[Asynchronous]` (default `async`), and your `framework.messenger.routing`.

```bash
bin/console messenger:consume async_commands async_events
```

A handler declared with an explicit bus
(`#[AsCommandHandler(ShipOrder::class, bus: 'messenger.bus.commands')]`) is only
registered on that bus. If the message is also dispatched asynchronously,
repeat the attribute for the async bus, otherwise the worker fails with
`No handler for message`.

### Recommended flags

```bash
bin/console messenger:consume async_commands \
    --time-limit=3600 \
    --memory-limit=256M \
    --sleep=1
```

| Flag | Purpose |
|------|---------|
| `--time-limit=3600` | Stop the worker after one hour; the process manager starts a fresh one |
| `--memory-limit=256M` | Stop when memory usage exceeds the limit |
| `--limit=1000` | Stop after handling this many messages |
| `--sleep=1` | Seconds to wait when no message is available |

Run separate workers per transport when you want to scale commands and events
independently.

### Supervisor

```ini
; /etc/supervisor/conf.d/cqrs-workers.conf

[program:cqrs-command-worker]
command=php /var/www/app/bin/console messenger:consume async_commands --time-limit=3600 --memory-limit=256M
autostart=true
autorestart=true
numprocs=2
process_name=%(program_name)s_%(process_num)02d
stdout_logfile=/var/log/supervisor/cqrs-command-worker.log
stderr_logfile=/var/log/supervisor/cqrs-command-worker-error.log
user=www-data

[program:cqrs-event-worker]
command=php /var/www/app/bin/console messenger:consume async_events --time-limit=3600 --memory-limit=256M
autostart=true
autorestart=true
numprocs=1
process_name=%(program_name)s_%(process_num)02d
stdout_logfile=/var/log/supervisor/cqrs-event-worker.log
stderr_logfile=/var/log/supervisor/cqrs-event-worker-error.log
user=www-data
```

### systemd

```ini
; /etc/systemd/system/cqrs-command-worker@.service

[Unit]
Description=CQRS Command Worker %i
After=network.target

[Service]
Type=simple
User=www-data
ExecStart=/usr/bin/php /var/www/app/bin/console messenger:consume async_commands --time-limit=3600 --memory-limit=256M
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
# Start two worker instances
systemctl enable --now cqrs-command-worker@1
systemctl enable --now cqrs-command-worker@2
```

### Deployments and shutdown

* With the `pcntl` extension, `SIGTERM`, `SIGINT` and `SIGQUIT` make a worker
  finish the current message and exit. `--time-limit`, `--memory-limit` and
  `--limit` stop it the same way.
* After deploying new code, run `bin/console messenger:stop-workers` so running
  workers exit after their current message and restart with the new code.
* Symfony resets services tagged `kernel.reset` between messages (unless you
  pass `--no-reset`). The bundle's `CausationIdContext` is one of them, so a
  causation id never leaks from one message to the next.

## Retries and failed messages

### Transport-level retries

Messenger retries a failed message on the transport it was received from. To
drive those retries per message class, combine three settings:

```yaml
# config/services.yaml
services:
    app.retry.payment:
        class: SomeWork\CqrsBundle\Support\ExponentialBackoffRetryPolicy
        arguments:
            $maxRetries: 5
            $initialDelay: 1000      # milliseconds
            $multiplier: 2.0

# config/packages/messenger.yaml
framework:
    messenger:
        failure_transport: failed
        transports:
            async_commands:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy:        # used for messages without a RetryConfiguration
                    max_retries: 3
                    delay: 1000
                    multiplier: 2
            failed: 'doctrine://default?queue_name=failed'

# config/packages/somework_cqrs.yaml
somework_cqrs:
    retry_policies:
        command:
            map:
                App\Application\Command\ProcessPayment: app.retry.payment
    retry_strategy:
        transports:
            async_commands: command
        jitter: 0.1
        max_delay: 60000
```

* `retry_strategy.transports` replaces the retry strategy of `async_commands`
  with `CqrsRetryStrategy`. For each failed message it resolves the
  `retry_policies.command` entry.
* When the policy implements `RetryConfiguration` (as
  `ExponentialBackoffRetryPolicy` does), its values apply: here 5 retries with
  delays of about 1, 2, 4, 8 and 16 seconds, varied by up to 10% and capped at
  60 seconds.
* Other messages use the transport's own `retry_strategy`. Without one,
  Messenger's defaults apply: 3 retries, 1000 ms delay, multiplier 2.
* `ExponentialBackoffRetryPolicy` adds no stamps when the message is dispatched.
  Without `retry_strategy.transports` its values are not used at all.

See [Retry strategy bridge](retry.md) for the details and for custom policies.

### Failure transport

After the last retry, Messenger moves the message to the `failure_transport`
(`failed` above). The bundle does not change this pipeline.

```bash
# List failed messages, or count them by class
bin/console messenger:failed:show
bin/console messenger:failed:show --stats

# Show one message, retry it or remove it
bin/console messenger:failed:show 42
bin/console messenger:failed:retry 42
bin/console messenger:failed:remove 42

# Retry all failed messages interactively
bin/console messenger:failed:retry
```

`bin/console messenger:stats` shows how many messages wait in each transport
(for transports that can count them). A growing failure transport means handlers
keep failing after their retries; the custom health check in
[Health checks](#health-checks) turns that into a warning.

## Idempotency

`IdempotencyStamp` deduplication relies on Messenger's deduplicate middleware.
It only works when:

* symfony/messenger is 7.3 or newer;
* symfony/lock is installed;
* the lock component is enabled (`framework.lock`), with a store that all
  application and worker processes share and that keeps locks until their TTL
  expires, such as Redis or a database.

```yaml
framework:
    lock: '%env(LOCK_DSN)%'       # e.g. redis://redis:6379 or the DSN of your database

somework_cqrs:
    idempotency:
        ttl: 3600                 # seconds a key stays locked
```

Do not rely on the local `flock` or `semaphore` stores for deduplication: they
release a lock as soon as Messenger's lock object is gone, so a second dispatch
with the same key goes through.

Missing pieces do not break the build. In debug mode the reason is written to
the container compilation log (`var/cache/<env>/*Compiler.log`), for example:

```
Idempotency is enabled but Messenger's deduplicate middleware is not registered, so DeduplicateStamp is not enforced. Enable the lock component ("framework.lock").
```

For a synchronous dispatch the key stays locked for `ttl` seconds after the
message was handled; a duplicate makes `dispatchSync()` / `ask()` throw
`DuplicateMessageException`. If the handler fails, the lock is released so the
caller can retry. For an asynchronous dispatch, the lock is released once a
worker handled the message. See [Idempotency](idempotency.md).

## Outbox operations

With `outbox.enabled: true`, you store messages in the outbox table inside your
database transaction and a relay sends them to Messenger afterwards. See
[Transactional outbox](outbox.md) for storing messages.

### Table

The table is created on first use (`auto_setup: true`), but never inside an open
transaction: storing the first message inside a transaction throws a
`LogicException` if the table does not exist yet. Create it, or upgrade a table of
an earlier version, during deployment:

```bash
bin/console somework:cqrs:outbox:setup
```

If Doctrine migrations manage your schema, set `outbox.auto_setup: false`. With
doctrine/orm installed, `doctrine:migrations:diff` includes the outbox table of
the configured connection.

### Relay

`somework:cqrs:outbox:relay` sends up to `--limit` (default 100) due rows (new
rows in the order they were stored, then retries in the order of their retry
time) and marks each one published after dispatching it.

* Rows are dispatched on the Messenger bus of their type (the async command or
  event bus when configured, otherwise the sync one; the default bus for other
  messages) with their stored transport name as `TransportNamesStamp`; rows
  without a transport name follow `framework.messenger.routing`. Workers then
  hand each message to the bus where its handlers are registered. A row that is
  not sent to any transport is handled synchronously, and the command prints and
  logs a warning.
* The stamp pipeline does not run for relayed messages: add the stamps you need
  (for example a `MessageMetadataStamp`) to the envelope you store.
* A row that fails is logged, postponed (1 minute, doubling up to 1 hour) and
  makes the command exit with `1`; the rows behind it are not blocked. After
  `outbox.max_attempts` attempts (default 10) the relay gives up on the row.
* The transports take turns, so one transport's backlog does not hold up the
  others.
* The transports take turns, so one transport's backlog does not hold up the
  others.
* A transport that fails 3 times in a row with a `TransportException` (broker
  down, or rejecting messages) is paused until the next run, while the rows of
  the other transports are relayed (10 times when it accepted a message earlier
  in the run: it is up and only rejects some messages). Its rows get three times `max_attempts`
  (about a day) before they are given up. If the database fails, the run stops
  right away.
* Delivery is at least once: if the process stops between dispatching a row and
  marking it published, the row is sent again. Make handlers idempotent.
* SIGTERM and SIGINT (with the `pcntl` extension) let the relay finish the
  current row, then it exits with `1`. A deploy or a container stop therefore
  does not leave a row half done.
* When symfony/lock is installed, only one relay runs at a time; a second one
  prints "Another outbox relay is already running." and exits with `0`. The lock
  uses `lock.factory` when `framework.lock` is enabled. Otherwise it is a local
  lock, which only protects relays on the same host. The lock name includes
  `framework.cache.prefix_seed`; set it to a stable value when every release is
  deployed to a new directory, so old and new relays share the lock.

Run the relay from cron:

```bash
# crontab: relay every minute, purge published rows every night
* * * * * cd /var/www/app && php bin/console somework:cqrs:outbox:relay --limit=500
0 3 * * * cd /var/www/app && php bin/console somework:cqrs:outbox:purge --older-than="7 days"
```

or as a supervised loop when a minute of latency is too much:

```ini
[program:cqrs-outbox-relay]
command=/bin/sh -c 'while true; do php /var/www/app/bin/console somework:cqrs:outbox:relay --limit=100; sleep 1; done'
autostart=true
autorestart=true
user=www-data
```

### Purge

`somework:cqrs:outbox:purge --older-than="7 days"` deletes rows published before
the given age (a relative date such as `"12 hours"`; default `7 days`).
Unpublished rows are never deleted.

### Given-up rows

Monitor the relay's exit code and the given-up rows:

```bash
bin/console somework:cqrs:outbox:failed              # what the relay gave up on, and why
bin/console somework:cqrs:outbox:failed --requeue    # after fixing the cause
```

A broker outage uses up attempts slowly: rows whose transport fails get three
times `max_attempts` (30 attempts by default, about a day of retries), and each
run tries 3 rows of a failing transport (3 of the rows stored for it by name, and
3 of the rows without a transport name routed to it), new ones first. Once the
outage is over, requeue the rows it gave up on. The health check warns while
rows keep failing, long before they are given up.

## Health checks

`somework:cqrs:health` checks that the CQRS infrastructure can start:

* **handler**: every CQRS handler service is instantiated. A handler that cannot
  be built (missing environment variable, failing constructor) is `CRITICAL`; no
  handlers at all is a `WARNING`.
* **transport**: every Messenger transport is instantiated, which validates its
  DSN and options. For the built-in transports this does not connect to the
  broker.
* **outbox** (when `outbox.enabled`): a `WARNING` when the relay gave up on
  rows, when failed rows wait for another attempt and the oldest was stored more
  than 10 minutes ago, or when due rows have waited more than 10 minutes;
  `CRITICAL` when the table cannot be read.

The command prints a table of results and exits with the highest severity:
`0` OK, `1` warnings, `2` critical. A checker that throws is reported as
`CRITICAL`.

Use it in a deployment pipeline or as a probe. Container orchestrators treat any
non-zero exit code as a failure; to fail only on critical issues:

```bash
php bin/console somework:cqrs:health; test $? -lt 2
```

### Custom checks

Implement `SomeWork\CqrsBundle\Health\HealthChecker` (`@api`). With
autoconfiguration the service is tagged `somework_cqrs.health_checker` and its
results appear in the command:

```php
<?php

declare(strict_types=1);

namespace App\Health;

use SomeWork\CqrsBundle\Health\CheckResult;
use SomeWork\CqrsBundle\Health\CheckSeverity;
use SomeWork\CqrsBundle\Health\HealthChecker;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

final class FailedMessagesChecker implements HealthChecker
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.failed')]
        private readonly object $failureTransport,
    ) {
    }

    public function check(): array
    {
        if (!$this->failureTransport instanceof MessageCountAwareInterface) {
            return [];
        }

        $count = $this->failureTransport->getMessageCount();

        return [new CheckResult(
            $count > 0 ? CheckSeverity::WARNING : CheckSeverity::OK,
            'failed_messages',
            sprintf('%d message(s) in the failure transport', $count),
        )];
    }
}
```

## Observability

### Correlation and causation ids

Every message dispatched through the facades gets a `MessageMetadataStamp` with a
correlation id (by default a random one from `RandomCorrelationMetadataProvider`).
When a handler dispatches further messages, `CausationIdMiddleware` and
`CausationIdStampDecider` set their causation id to the parent's correlation id,
so you can rebuild the chain of messages from your logs.

Read the stamp in a handler through `EnvelopeAware`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Command;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;

#[AsCommandHandler(ProcessPayment::class)]
final class ProcessPaymentHandler implements EnvelopeAware
{
    use EnvelopeAwareTrait;

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessPayment $command): mixed
    {
        $metadata = $this->getEnvelope()->last(MessageMetadataStamp::class);

        $this->logger->info('Processing payment', [
            'correlation_id' => $metadata?->getCorrelationId(),
            'causation_id' => $metadata?->getCausationId(),
            'payment_id' => $command->paymentId,
        ]);

        $this->gateway->charge($command->paymentId);

        return null;
    }
}
```

To continue a correlation id that came with a request, pass your own stamp; a
`MessageMetadataStamp` from the caller is kept:

```php
<?php

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;

$correlationId = $request->headers->get('X-Correlation-Id');
$stamps = null !== $correlationId && '' !== $correlationId
    ? [new MessageMetadataStamp($correlationId)]
    : [];

$commandBus->dispatch(new ProcessPayment($paymentId), DispatchMode::DEFAULT, ...$stamps);
```

### OpenTelemetry

The bundle traces messages when `open-telemetry/api` (1.8 or newer) is installed
and the container has an `OpenTelemetry\API\Trace\TracerProviderInterface`
service. The service must exist when the container is compiled; otherwise the
middleware is not registered. For example, to use the global tracer provider set
up by the OpenTelemetry SDK:

```yaml
# config/services.yaml
services:
    OpenTelemetry\API\Trace\TracerProviderInterface:
        factory: ['OpenTelemetry\API\Globals', 'tracerProvider']
```

What you get:

* `cqrs.dispatch <ShortClassName>` spans (kind `PRODUCER`) where messages are
  dispatched; for synchronous dispatches the span also covers the handlers;
* `cqrs.consume <ShortClassName>` spans (kind `CONSUMER`) in the worker;
* attributes `cqrs.message.class` and `cqrs.message.type`, and status `ERROR`
  with the recorded exception when handling fails;
* trace propagation: the dispatch adds a `TraceContextStamp` with the W3C trace
  headers, and the worker's span continues that trace.

See [Middleware: OpenTelemetryMiddleware](middleware.md#opentelemetrymiddleware)
for the details.

## Message versioning

Messages waiting in a transport were serialized with the old version of their
class. Plan changes to message classes with that in mind.

**Renaming or moving a class** breaks the messages already queued: the serialized
data refers to the old class name. Drain the queue before deploying the rename,
or keep the old class until no message of it is left:

```bash
# Consume what is left, then deploy the rename
bin/console messenger:consume async_commands --time-limit=300
```

**Removing or renaming a property** loses the data of queued messages or makes
them fail to decode.

**Adding a property** depends on the serializer:

* Messenger's default PHP serializer restores objects without calling the
  constructor. A new promoted property stays uninitialized in queued messages,
  and reading it throws an `Error`, even when the constructor parameter has a
  default value.
* The Symfony Serializer (`messenger.transport.symfony_serializer`) creates the
  object through its constructor, so a new constructor parameter with a default
  value is safe.

```yaml
framework:
    messenger:
        serializer:
            default_serializer: messenger.transport.symfony_serializer
```

The outbox relay decodes stored rows with `outbox.serializer`
(`messenger.default_serializer` by default), so the same rules apply to rows
waiting in the outbox table.
