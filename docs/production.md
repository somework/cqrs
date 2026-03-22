# Production deployment guide

This guide covers retry configuration, dead letter queues, message versioning,
monitoring integration, and worker lifecycle for running the CQRS bundle in
production.

## Retry configuration

The bundle provides retry policies via the `somework_cqrs.retry_policies`
config. A retry policy appends Messenger stamps at dispatch time. The built-in
`ExponentialBackoffRetryPolicy` adds a `DelayStamp` with the configured initial
delay; full exponential backoff across retries is handled by Symfony Messenger's
transport-level `MultiplierRetryStrategy`.

### Bundle-level retry config

Configure per-type defaults and per-message overrides:

```yaml
somework_cqrs:
    retry_policies:
        command:
            default: SomeWork\CqrsBundle\Support\NullRetryPolicy
            map:
                App\Application\Command\ProcessPayment: app.retry.exponential
        event:
            default: SomeWork\CqrsBundle\Support\NullRetryPolicy
            map:
                App\Domain\Event\OrderShipped: app.retry.exponential
```

### Registering ExponentialBackoffRetryPolicy as a service

```yaml
# config/services.yaml
services:
    app.retry.exponential:
        class: SomeWork\CqrsBundle\Support\ExponentialBackoffRetryPolicy
        arguments:
            $maxRetries: 5
            $initialDelay: 1000      # milliseconds
            $multiplier: 2.0
```

The policy accepts three constructor parameters:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `$maxRetries` | 3 | Maximum number of retry attempts |
| `$initialDelay` | 1000 | Initial delay in milliseconds before the first retry |
| `$multiplier` | 2.0 | Multiplier applied to the delay on each subsequent retry |

### Messenger transport retry strategy

The bundle's `ExponentialBackoffRetryPolicy` sets the initial `DelayStamp` at
dispatch time. For full backoff across retries, configure the transport-level
strategy to match:

```yaml
framework:
    messenger:
        transports:
            async_commands:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy:
                    max_retries: 5
                    delay: 1000
                    multiplier: 2.0
                    max_delay: 60000
```

The transport strategy controls what happens on failure retries. Keep its
`max_retries`, `delay`, and `multiplier` consistent with the
`ExponentialBackoffRetryPolicy` arguments for predictable behavior.

---

## Dead letter queue (DLQ) setup

Dead letter handling is a Symfony Messenger transport concern. The bundle does
not manage DLQ directly, but messages dispatched through CQRS buses flow
through Messenger's standard failure pipeline.

### Configure a failure transport

```yaml
framework:
    messenger:
        failure_transport: failed

        transports:
            failed:
                dsn: 'doctrine://default?queue_name=failed'
            async_commands:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy:
                    max_retries: 3
```

After exhausting retries, Messenger moves the message to the `failed` transport.

### Managing failed messages

```bash
# List failed messages
bin/console messenger:failed:show

# Show details of a specific failed message
bin/console messenger:failed:show 42

# Retry a specific failed message
bin/console messenger:failed:retry 42

# Retry all failed messages
bin/console messenger:failed:retry

# Remove a failed message without retrying
bin/console messenger:failed:remove 42
```

### Health check recommendation

Monitor the count of messages in the failed transport as a health check metric.
A growing count indicates handlers are failing beyond their retry limit:

```bash
# Count failed messages (useful for monitoring scripts)
bin/console messenger:failed:show --format=json | jq 'length'
```

---

## Message Versioning strategy

Messages are serialized DTOs. When messages sit in a transport queue, changes to
the message class affect deserialization. Follow these rules to evolve messages
safely.

### Safe changes

**Adding a new property with a default value** is always safe. Queued messages
without the new property will deserialize using the default:

```php
final class CreateTask implements Command
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        // Added in v2 -- safe because it has a default
        public readonly int $priority = 0,
    ) {}
}
```

### Breaking changes

The following changes break deserialization of messages already in the queue:

- **Removing a property** -- queued messages contain the old property, and the
  serializer will fail or silently drop data.
- **Renaming a property** -- the serializer maps by property name, so the old
  name will not match.
- **Renaming the message class (FQCN)** -- Messenger stores the FQCN as the
  message type identifier. Renaming breaks lookup of queued messages.

### Migrating message class names

If you must rename a message class, configure a Messenger serializer that maps
old class names to new ones. Alternatively, drain the queue before deploying the
rename:

```bash
# Drain specific message type before deploying rename
bin/console messenger:consume async_commands --time-limit=300
# Deploy with new class name after queue is empty
```

For long-lived queues, implement a `MessageNamingStrategy` that provides stable
logical names decoupled from PHP class names. Configure it via:

```yaml
somework_cqrs:
    naming:
        default: App\Messenger\StableNamingStrategy
```

### Serializer configuration

Use Symfony's built-in Messenger serializer for type-safe deserialization:

```yaml
framework:
    messenger:
        serializer:
            default_serializer: messenger.transport.symfony_serializer
```

---

## Monitoring integration

The bundle provides two mechanisms for distributed tracing: correlation IDs via
`MessageMetadataStamp` and causation ID propagation via `CausationIdContext`.

### Correlation ID

Every dispatched message receives a `MessageMetadataStamp` containing a
`correlationId`. This ID is generated by the configured `MessageMetadataProvider`
(defaults to `RandomCorrelationMetadataProvider`).

Log the correlation ID in handlers for end-to-end tracing:

```php
use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\CommandHandler;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use Symfony\Component\Messenger\Envelope;

#[AsCommandHandler(command: ProcessPayment::class)]
final class ProcessPaymentHandler implements CommandHandler, EnvelopeAware
{
    private ?Envelope $envelope = null;

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessPayment $command): mixed
    {
        $metadata = $this->envelope?->last(MessageMetadataStamp::class);
        $correlationId = $metadata?->getCorrelationId() ?? 'unknown';
        $causationId = $metadata?->getCausationId() ?? 'none';

        $this->logger->info('Processing payment', [
            'correlationId' => $correlationId,
            'causationId' => $causationId,
            'paymentId' => $command->paymentId,
        ]);

        $this->gateway->charge($command->paymentId);

        return null;
    }

    public function setEnvelope(Envelope $envelope): void
    {
        $this->envelope = $envelope;
    }
}
```

### Causation ID propagation

When a handler dispatches a child message, the `CausationIdMiddleware`
automatically pushes the parent's correlation ID onto the `CausationIdContext`
stack. The `CausationIdStampDecider` reads this context and injects the
`causationId` into the child message's `MessageMetadataStamp`.

This creates a causal chain: parent correlation ID becomes the child's causation
ID. Use this to reconstruct the full message graph in logs or tracing systems.

### HTTP response headers

Expose the correlation ID in HTTP responses for end-to-end tracing from client
to worker:

```php
// In a Symfony event listener or middleware
$response->headers->set('X-Correlation-Id', $correlationId);
```

### Health check via HandlerRegistry

The `HandlerRegistry` service holds compiled handler metadata. Use it to verify
all expected handlers are registered at boot time:

```php
use SomeWork\CqrsBundle\Registry\HandlerRegistry;

final class CqrsHealthCheck
{
    public function __construct(private readonly HandlerRegistry $registry) {}

    public function check(): bool
    {
        // Verify critical handlers are registered
        return $this->registry->has(ProcessPayment::class)
            && $this->registry->has(ShipOrder::class);
    }
}
```

---

## Worker lifecycle

Symfony Messenger workers consume messages from transports. Proper worker
configuration prevents memory leaks and ensures reliable message processing.

### Basic worker command

```bash
bin/console messenger:consume async_commands async_events
```

### Recommended flags for production

```bash
bin/console messenger:consume async_commands \
    --time-limit=3600 \
    --memory-limit=256M \
    --sleep=1
```

| Flag | Purpose |
|------|---------|
| `--time-limit=3600` | Restart the worker after 1 hour to prevent memory leaks |
| `--memory-limit=256M` | Restart when memory usage exceeds the limit |
| `--sleep=1` | Seconds to sleep when no messages are available |
| `--bus=messenger.bus.commands_async` | Consume only from a specific bus |

### Bus-specific workers

Run separate workers per bus for isolation and independent scaling:

```bash
# Command worker
bin/console messenger:consume async_commands --bus=messenger.bus.commands_async

# Event worker
bin/console messenger:consume async_events --bus=messenger.bus.events_async
```

### Supervisord configuration

Use a process manager to keep workers running. Example supervisord config:

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

### Systemd alternative

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
# Start 2 worker instances
systemctl enable --now cqrs-command-worker@1
systemctl enable --now cqrs-command-worker@2
```

### Signal handling and graceful shutdown

Workers respond to POSIX signals:

- `SIGTERM` / `SIGINT` -- finish the current message, then stop
- `SIGUSR1` -- reserved by Symfony for internal use

The `--time-limit` and `--memory-limit` flags trigger graceful shutdown after
the current message completes. The process manager then restarts the worker
automatically.

### CausationIdContext reset

The `CausationIdContext` is tagged with `kernel.reset` in the DI container.
Between messages, the Symfony kernel resets the context stack, preventing
causation ID leakage across unrelated messages in the same worker process. No
manual cleanup is needed.
