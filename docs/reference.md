# Configuration reference

The bundle is configured under the `somework_cqrs` key. Every option is
optional: without any configuration the facades use Messenger's default bus,
dispatch everything synchronously and add only a correlation id to each
message. You can print the tree for your installed version with
`bin/console config:dump-reference somework_cqrs`.

## Defaults at a glance

This is the complete tree with its default values:

```yaml
# config/packages/somework_cqrs.yaml
somework_cqrs:
    default_bus: null
    buses:
        command: null
        command_async: null
        query: null
        event: null
        event_async: null
    naming:
        default: SomeWork\CqrsBundle\Policy\ClassNameMessageNamingStrategy
        command: { default: null }
        query: { default: null }
        event: { default: null }
    retry_policies:
        default: SomeWork\CqrsBundle\Policy\NullRetryPolicy
        command: { default: null, map: {} }
        query: { default: null, map: {} }
        event: { default: null, map: {} }
    retry_strategy:
        transports: {}
        jitter: 0.0
        max_delay: 0
    serialization:
        default: SomeWork\CqrsBundle\Policy\NullMessageSerializer
        command: { default: null, map: {} }
        query: { default: null, map: {} }
        event: { default: null, map: {} }
    metadata:
        default: SomeWork\CqrsBundle\Policy\RandomCorrelationMetadataProvider
        command: { default: null, map: {} }
        query: { default: null, map: {} }
        event: { default: null, map: {} }
    dispatch_modes:
        command: { default: sync, map: {} }
        event: { default: sync, map: {} }
    transports:
        command: { default: [], map: {} }
        command_async: { default: [], map: {} }
        query: { default: [], map: {} }
        event: { default: [], map: {} }
        event_async: { default: [], map: {} }
    dispatch_after_current_bus:
        command: { default: true, map: {} }
        event: { default: true, map: {} }
    idempotency:
        enabled: true
        ttl: 300
    causation_id:
        enabled: true
        buses: []
    sequence:
        enabled: true
    rate_limiting:
        enabled: true
        default: null
        command: { default: null, map: {} }
        query: { default: null, map: {} }
        event: { default: null, map: {} }
    outbox:
        enabled: false
        table_name: somework_cqrs_outbox
        connection: default
        serializer: messenger.default_serializer
        storage: null
        auto_setup: true
        max_attempts: 10
        signing:
            enabled: true
            secret: null
            previous_secrets: []
            accept_unsigned: false
```

## Rules that apply to every section

**Service ids and names.** Options that name a service or another configured
item (buses, naming strategies, retry policies, serializers, metadata
providers, transport names, rate limiter names, the outbox table, connection
and serializer) must be non-empty strings. Options whose default is
`null` also accept `null`. A blank value fails with:

```
Invalid configuration for path "somework_cqrs.buses.command": Expected a non-empty string or null, got "".
```

For `naming`, `retry_policies`, `serialization` and `metadata` you may use a
fully-qualified class name instead of a service id. When no service with that
id exists and the class is concrete, the bundle registers it as a private,
autowired and autoconfigured service. Any other unknown id makes the container
compilation fail with the option that names it:

```
The service "app.retry.payment" configured at "somework_cqrs.retry_policies.command.map.App\Command\ChargePayment" does not exist.
```

**One shape for every per-message section.** `retry_policies`, `serialization`,
`metadata` and `rate_limiting` have a global `default` and, per message type, a
`default` (`null` falls back to the global one) and a `map`. `naming` has the
defaults only. `dispatch_modes`, `transports` and `dispatch_after_current_bus`
have no global default, because it would also apply to synchronous messages.
Options of earlier versions fail with where they moved, e.g.
`"somework_cqrs.async.dispatch_after_current_bus" moved to "somework_cqrs.dispatch_after_current_bus".`

**Per-message maps.** Every `map` is keyed by a message class or interface.
Keys must name an existing class or interface; a leading backslash is removed.
A typo fails the container compilation instead of silently never matching:

```
Invalid configuration for path "somework_cqrs.retry_policies.command.map": "App\Command\ShipOrdr" is not an existing class or interface; keys must be message class or interface names.
```

**Compile-time flags.** The `enabled` flags of `outbox`, `idempotency`,
`causation_id`, `sequence` and `rate_limiting` decide which services exist, so
they must be literal booleans. An environment variable (`%env(...)%`) is
rejected:

```
"somework_cqrs.outbox.enabled" decides which services are registered when the container is compiled, so it must be a boolean and cannot use an environment variable.
```

**Environment variables.** Only options read at runtime accept `%env(...)%`:
`retry_strategy.jitter`, `retry_strategy.max_delay`, `idempotency.ttl`,
`outbox.auto_setup`, `outbox.max_attempts`, `outbox.signing.secret`,
`outbox.signing.previous_secrets`, `outbox.signing.accept_unsigned` and the
`dispatch_after_current_bus` flags. Every other option names services,
buses, transports, dispatch modes or message classes that the container
compilation needs, and rejects an environment variable:

```
"somework_cqrs.dispatch_modes.command.default" is used when the container is compiled (it names services, buses, transports, dispatch modes or message classes), so it cannot use an environment variable.
```

## Resolution order for per-message maps

All `map` sections resolve a message the same way. For a dispatched message the
bundle looks for, in this order:

1. an entry for the exact message class;
2. an entry for a parent class, from the direct parent up;
3. an entry for an interface the message implements (including interfaces
   inherited from parents and parent interfaces);
4. the `default` of the message type section;
5. the global `default` of the section, where it has one.

What happens when nothing matches depends on the section:

| Section | Fallback after the map |
|---------|------------------------|
| `retry_policies.<type>` | `retry_policies.<type>.default`, then `retry_policies.default` |
| `serialization.<type>` | `serialization.<type>.default`, then `serialization.default` |
| `metadata.<type>` | `metadata.<type>.default`, then `metadata.default` |
| `rate_limiting.<type>` | `rate_limiting.<type>.default`, then `rate_limiting.default`, then no limiter |
| `transports.<bus>` | `transports.<bus>.default`; when that is empty, no stamp is added and Messenger routing applies |
| `dispatch_after_current_bus.<type>` | `dispatch_after_current_bus.<type>.default` |

**Dispatch modes** follow a slightly different order because the
`#[Asynchronous]` attribute takes part. For a command or event dispatched with
`DispatchMode::DEFAULT` (that is, `dispatch()` without a mode):

1. an entry for the exact class in `dispatch_modes.<type>.map`;
2. the `#[Asynchronous]` attribute on the message class (`SomeWork\CqrsBundle\Attribute\Asynchronous`) selects `async`;
3. an entry for a parent class;
4. an entry for an interface, the most derived interface first;
5. `dispatch_modes.<type>.default`.

An explicit mode always wins: `dispatch($message, DispatchMode::ASYNC)`,
`dispatchSync()` and `dispatchAsync()` skip this resolution. Queries are always
synchronous.

## default_bus

| | |
|---|---|
| Type | service id or `null` |
| Default | `null`, which means `messenger.default_bus` |

The Messenger bus used for `buses.command`, `buses.query` and `buses.event`
when they are not set. The async buses never fall back to it.

## buses

| Key | Default | Purpose |
|-----|---------|---------|
| `command` | `null` (falls back to `default_bus`) | Bus used by `CommandBus` for synchronous dispatch |
| `command_async` | `null` (async commands disabled) | Bus used by `CommandBus` for asynchronous dispatch |
| `query` | `null` (falls back to `default_bus`) | Bus used by `QueryBus` |
| `event` | `null` (falls back to `default_bus`) | Bus used by `EventBus` for synchronous dispatch |
| `event_async` | `null` (async events disabled) | Bus used by `EventBus` for asynchronous dispatch |

Values are Messenger bus service ids, as declared under
`framework.messenger.buses`.

```yaml
framework:
    messenger:
        default_bus: messenger.bus.commands
        buses:
            messenger.bus.commands: ~
            messenger.bus.commands_async: ~
            messenger.bus.queries: ~
            messenger.bus.events: ~
            messenger.bus.events_async: ~

somework_cqrs:
    buses:
        command: messenger.bus.commands
        command_async: messenger.bus.commands_async
        query: messenger.bus.queries
        event: messenger.bus.events
        event_async: messenger.bus.events_async
```

* A handler without an explicit `bus` is registered on the synchronous bus of
  its type and, when configured, on the async bus of that type, so a worker
  consuming async messages finds it. A handler with an explicit `bus` is
  registered on that bus only.
* The event buses (`event`, or `default_bus` when `event` is not set, and
  `event_async`) receive `AllowNoHandlerMiddleware`: an `Event` without
  handlers is not an error. Commands and queries without a handler still fail.
* Leaving an async bus at `null` disables async dispatch for that type. An
  async dispatch then throws `AsyncBusNotConfiguredException`. When the
  configuration itself asks for async delivery (an `async` dispatch mode, or
  entries under `transports.command_async` / `transports.event_async`), the
  container compilation fails instead:

  ```
  Asynchronous dispatch is configured for commands (the default dispatch mode is "async"), but "somework_cqrs.buses.command_async" is null. Define the Messenger bus id used for async commands before the container is compiled.
  ```
* Every configured bus id must be a Messenger bus (a service tagged
  `messenger.bus`, or an alias of one); anything else fails the compilation:

  ```
  "somework_cqrs.buses.event_async" is "event.asyn_bus", which is not a Messenger bus. Known buses: command.bus, event.async_bus, event.bus. Declare it under "framework.messenger.buses".
  ```

## naming

| Key | Default |
|-----|---------|
| `default` | `SomeWork\CqrsBundle\Policy\ClassNameMessageNamingStrategy` |
| `command.default`, `query.default`, `event.default` | `null` (use the global `default`) |

Services implementing `SomeWork\CqrsBundle\Contract\MessageNamingStrategy`
(`getName(string $messageClass): string`). They only produce the display names
shown by `somework:cqrs:list` and `HandlerRegistry::getDisplayName()`; they do
not affect routing or serialization. The default strategy returns the short
class name.

```yaml
somework_cqrs:
    naming:
        command:
            default: App\Infrastructure\Cqrs\CommandNamingStrategy
```

## retry_policies

A global `default` plus one section per message type (`command`, `query`,
`event`):

| Key | Default | Allowed values |
|-----|---------|----------------|
| `default` | `SomeWork\CqrsBundle\Policy\NullRetryPolicy` | service id or class name |
| `<type>.default` | `null` (use the global `default`) | service id or class name |
| `<type>.map` | `{}` | message class or interface => service id or class name |

Services implement `SomeWork\CqrsBundle\Contract\RetryPolicy`:

```php
<?php

namespace SomeWork\CqrsBundle\Contract;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use Symfony\Component\Messenger\Stamp\StampInterface;

interface RetryPolicy
{
    /**
     * @return list<StampInterface>
     */
    public function getStamps(object $message, DispatchMode $mode): array;
}
```

The returned stamps are added to every dispatch of a matching message, except
stamps of a class the caller already passed. When
the policy also implements `SomeWork\CqrsBundle\Contract\RetryConfiguration`
(`getMaxRetries()`, `getInitialDelay()`, `getMultiplier()`), transports listed
under [`retry_strategy`](#retry_strategy) use these values to retry failed
messages.

The bundle ships two policies:

* `NullRetryPolicy` adds nothing.
* `ExponentialBackoffRetryPolicy` adds no stamps and implements
  `RetryConfiguration` (defaults: 3 retries, 1000 ms initial delay, multiplier
  2.0). It is registered with these defaults as
  `somework_cqrs.exponential_backoff_retry_policy`; define your own service of
  the class to change the arguments (see [Retry strategy bridge](retry.md)).

Resolution: exact class, parent classes, interfaces, the type `default`, then
the global `default`.

```yaml
somework_cqrs:
    retry_policies:
        command:
            map:
                App\Application\Command\ProcessPayment: somework_cqrs.exponential_backoff_retry_policy
                App\Domain\Contract\RetriesAggressively: app.retry.aggressive
```

## retry_strategy

Replaces the retry strategy of Messenger transports with `CqrsRetryStrategy`,
which applies the per-message `RetryConfiguration` described above.

| Key | Default | Allowed values |
|-----|---------|----------------|
| `transports` | `{}` | list of Messenger transport names, or transport name => `command`, `query` or `event` |
| `jitter` | `0.0` | float between `0.0` and `1.0` |
| `max_delay` | `0` | integer >= 0, in milliseconds; `0` means no cap |

* Keys of `transports` are Messenger transport names, kept exactly as written.
  A name that is not a transport fails the compilation:

  ```
  Transport "async_comands" configured under "somework_cqrs.retry_strategy.transports" is not a Messenger transport. Known transports: "async_commands", "failed".
  ```

* Each message received from the transport uses the `retry_policies` section of
  its own type, so commands and events can share a transport. The value is the
  section used for messages that are neither commands, queries nor events; a
  plain list (`transports: [async]`) uses `command` for them.
* For a message whose policy implements `RetryConfiguration`, the message is
  retried while its retry count is below `getMaxRetries()`, with a delay of
  `initialDelay * multiplier ^ retryCount`. The delay is capped at `max_delay`,
  then varied by up to `jitter` in both directions (and capped again).
* Any other message falls back to the transport's own strategy, configured under
  `framework.messenger.transports.<name>.retry_strategy` (Messenger's defaults:
  3 retries, 1000 ms delay, multiplier 2).

```yaml
somework_cqrs:
    retry_strategy:
        transports:
            async_commands: command
            async_events: event
        jitter: 0.1
        max_delay: 60000
```

## serialization

| Key | Default | Allowed values |
|-----|---------|----------------|
| `default` | `SomeWork\CqrsBundle\Policy\NullMessageSerializer` | service id or class name |
| `command.default`, `query.default`, `event.default` | `null` (use `serialization.default`) | service id, class name or `null` |
| `command.map`, `query.map`, `event.map` | `{}` | message class or interface => service id or class name |

Services implement `SomeWork\CqrsBundle\Contract\MessageSerializer`:

```php
<?php

namespace SomeWork\CqrsBundle\Contract;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use Symfony\Component\Messenger\Stamp\SerializerStamp;

interface MessageSerializer
{
    public function getStamp(object $message, DispatchMode $mode): ?SerializerStamp;
}
```

A returned `SerializerStamp` (Messenger's serializer context) is added to the
dispatch; `null` adds nothing. The default serializer returns `null`. A
`SerializerStamp` passed by the caller wins and the serializer service is not
called.

Resolution: exact class, parent classes, interfaces, type `default`, global
`default`.

```yaml
somework_cqrs:
    serialization:
        event:
            default: app.event_serializer_context
            map:
                App\Domain\Event\OrderShipped: app.order_shipped_serializer_context
```

## metadata

| Key | Default | Allowed values |
|-----|---------|----------------|
| `default` | `SomeWork\CqrsBundle\Policy\RandomCorrelationMetadataProvider` | service id or class name |
| `command.default`, `query.default`, `event.default` | `null` (use `metadata.default`) | service id, class name or `null` |
| `command.map`, `query.map`, `event.map` | `{}` | message class or interface => service id or class name |

Services implement `SomeWork\CqrsBundle\Contract\MessageMetadataProvider`:

```php
<?php

namespace SomeWork\CqrsBundle\Contract;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;

interface MessageMetadataProvider
{
    public function getStamp(object $message, DispatchMode $mode): ?MessageMetadataStamp;
}
```

A provider returns a new stamp for each call (or `null`): the stamp carries the
message id. The default provider adds a `MessageMetadataStamp` with a random
32-character hexadecimal message id, which is also its correlation id. A
`MessageMetadataStamp` passed by the caller wins (use it to propagate a
correlation id you received). When a message is dispatched from inside a
handler, it inherits the correlation id of the handled message (see
[`causation_id`](#causation_id)).

Resolution: exact class, parent classes, interfaces, type `default`, global
`default`.

```yaml
somework_cqrs:
    metadata:
        command:
            map:
                App\Application\Command\ImportCatalog: app.import_metadata_provider
```

## dispatch_modes

One section each for `command` and `event` (queries are always synchronous):

| Key | Default | Allowed values |
|-----|---------|----------------|
| `default` | `sync` | `sync` or `async` |
| `map` | `{}` | message class or interface => `sync` or `async` |

The mode is used when the caller does not choose one (`dispatch()` with
`DispatchMode::DEFAULT`). See the [resolution order](#resolution-order-for-per-message-maps)
above, including the `#[Asynchronous]` attribute. Any `async` value requires
the matching async bus (`buses.command_async` / `buses.event_async`). An invalid
value fails the compilation:

```
Invalid configuration for path "somework_cqrs.dispatch_modes.command.map.App\Application\Command\GenerateReport": Invalid dispatch mode ""later"". Expected "sync" or "async".
```

```yaml
somework_cqrs:
    dispatch_modes:
        command:
            default: sync
            map:
                App\Application\Command\GenerateReport: async
        event:
            default: async
            map:
                App\Domain\Event\PasswordChanged: sync
```

## transports

Five sections: `command`, `command_async`, `query`, `event` and `event_async`.
The section is chosen by message type and resolved dispatch mode (a command
dispatched asynchronously uses `command_async`).

| Key | Default | Allowed values |
|-----|---------|----------------|
| `default` | `[]` | list of transport names (a single string is accepted) |
| `map` | `{}` | message class or interface => list of transport names (or one string) |

When a transport list resolves for a message, the bundle adds Messenger's
`TransportNamesStamp`. The stamp replaces `framework.messenger.routing` for that
message: Messenger sends it to exactly these transports.

* Every transport name must be defined under `framework.messenger.transports`;
  otherwise compilation fails with
  `Messenger transport "..." configured for SomeWork CQRS is not defined.`
* The `*_async` sections are only used when the matching async bus is
  configured, and any entry in them without that bus fails the compilation (see
  [`buses`](#buses)).
* A `TransportNamesStamp` passed by the caller wins. On asynchronous dispatches,
  `#[Asynchronous(transport: '...')]` beats parent/interface entries and the
  `default`, but not an entry for exactly the message class; a bare
  `#[Asynchronous]` only adds the `async` transport when nothing is configured
  and neither `framework.messenger.routing` nor `#[AsMessage(transport: ...)]` routes the message.
* Transports on the synchronous sections (`command`, `query`, `event`) send the
  message away instead of handling it in-process (unless the transport is
  `sync://`). `CommandBus::dispatchSync()` and `QueryBus::ask()` then throw
  `MessageSentToTransportException`.

Resolution: exact class, parent classes, interfaces, then the section
`default`. When nothing resolves, no stamp is added and Messenger's routing
applies.

```yaml
somework_cqrs:
    transports:
        command_async:
            default: async_commands
            map:
                App\Application\Command\ShipOrder: [high_priority]
        event_async:
            default: [async_events]
            map:
                App\Domain\Event\OrderShipped: [async_events, audit_log]
```

## dispatch_after_current_bus

Controls Messenger's `DispatchAfterCurrentBusStamp` on asynchronous dispatches.
With the stamp, a message dispatched while another message is being handled is
only sent after that handler finished successfully, and dropped when it fails.

| Key | Default | Allowed values |
|-----|---------|----------------|
| `command.default`, `event.default` | `true` | boolean |
| `command.map`, `event.map` | `{}` | message class or interface => boolean |

* Applies to asynchronous dispatches only.
* A `DispatchAfterCurrentBusStamp` passed by the caller is kept.
* `dispatchSync()` and `ask()` remove the stamp, because they need the result
  immediately.

Resolution: exact class, parent classes, interfaces, then the type `default`.

```yaml
somework_cqrs:
    dispatch_after_current_bus:
        command:
            map:
                App\Application\Command\SendAlert: false
```

## idempotency

Bridges the bundle's `IdempotencyStamp` to Messenger's deduplication.

| Key | Default | Allowed values |
|-----|---------|----------------|
| `enabled` | `true` | boolean (no environment variables) |
| `ttl` | `300` | integer >= 1, lock lifetime in seconds |

When a dispatch carries `new IdempotencyStamp('key')`, the bundle adds a
`DeduplicateStamp` whose key is `<message class>::<key>` and whose TTL is
`ttl`. The `IdempotencyStamp` stays on the envelope, and a `DeduplicateStamp`
passed by the caller is kept.

Deduplication only happens when all of the following hold. Missing pieces do
not fail the build; the reason is written to the container compilation log.

* symfony/messenger 7.3 or newer (it provides `DeduplicateStamp`);
* symfony/lock is installed;
* the lock component is enabled (`framework.lock`), which registers Messenger's
  deduplicate middleware, with a lock store shared by all processes that keeps
  locks until their TTL expires (see
  [Production: idempotency](production.md#idempotency)).

When a synchronous dispatch fails, the bundle releases the lock again, so a
retry with the same key is not rejected. See [Idempotency](idempotency.md).

```yaml
framework:
    lock: '%env(LOCK_DSN)%'

somework_cqrs:
    idempotency:
        ttl: 3600
```

## causation_id

| Key | Default | Allowed values |
|-----|---------|----------------|
| `enabled` | `true` | boolean (no environment variables) |
| `buses` | `[]` | list of Messenger bus service ids |

While a handler runs, `CausationIdMiddleware` keeps the `MessageMetadataStamp`
of the message being handled. Messages dispatched from that handler get its
message id as their causation id (an explicit causation id is kept) and, unless
the caller passed its own stamp, its correlation id. With `enabled: false`,
every message starts its own flow.

`buses` limits the middleware to the listed buses; the empty default means all
buses used by the bundle (`default_bus` and every configured `buses.*`). The
other CQRS buses get a variant that only hides the outer message: messages
dispatched by their handlers start a new flow instead of naming an unrelated
message as their cause. Each entry must be a Messenger bus:

```
"somework_cqrs.causation_id.buses" contains "messenger.bus.comands", which is not a Messenger bus service id.
```

`enabled: false` removes both the middleware and the stamp decider.

```yaml
somework_cqrs:
    causation_id:
        buses:
            - messenger.bus.commands
            - messenger.bus.commands_async
```

## sequence

| Key | Default | Allowed values |
|-----|---------|----------------|
| `enabled` | `true` | boolean (no environment variables) |

Events implementing `SomeWork\CqrsBundle\Contract\SequenceAware`
(`getAggregateId()`, `getSequenceNumber()`) receive an `AggregateSequenceStamp`
(aggregate id, sequence number and the event class as aggregate type). A stamp
passed by the caller is kept. See [Event ordering](event-ordering.md).

```yaml
somework_cqrs:
    sequence:
        enabled: false
```

## rate_limiting

| Key | Default | Allowed values |
|-----|---------|----------------|
| `enabled` | `true` | boolean (no environment variables) |
| `default` | `null` | rate limiter name applied to every message without a more specific entry |
| `command.default`, `query.default`, `event.default` | `null` (use the global `default`) | rate limiter name |
| `command.map`, `query.map`, `event.map` | `{}` | message class or interface => rate limiter name |

Values are limiter names from `framework.rate_limiter` (the bundle uses the
`limiter.<name>` service; a name that is not defined fails the compilation).
Rate limiting is inactive while no limiter is configured. Configuring a limiter
requires symfony/rate-limiter:

```
Rate limiters are configured under "somework_cqrs.rate_limiting" but symfony/rate-limiter is not installed. Run "composer require symfony/rate-limiter" or remove them.
```

Each dispatch of a matching message consumes one token. The limiter key is the
message class, so a `default` gives every message class its own bucket, not one
shared bucket. When no token is left, the dispatch throws
`RateLimitExceededException` before anything is sent. Resolution: exact class,
parent classes, interfaces, the type `default`, then the global `default`;
messages without any of them are not limited. See
[Rate limiting](rate-limiting.md).

```yaml
framework:
    rate_limiter:
        send_notification:
            policy: sliding_window
            limit: 100
            interval: '1 minute'

somework_cqrs:
    rate_limiting:
        command:
            map:
                App\Application\Command\SendNotification: send_notification
```

## outbox

| Key | Default | Allowed values |
|-----|---------|----------------|
| `enabled` | `false` | boolean (no environment variables) |
| `storage` | `null` | service id or class name of an `OutboxStorage`; `null` for the DBAL storage below |
| `table_name` | `somework_cqrs_outbox` | letters, digits and underscores, optionally `schema.table` (`database.table` on MySQL); avoid reserved SQL words |
| `connection` | `default` | DBAL connection name; the service `doctrine.dbal.<name>_connection` (DoctrineBundle) is used |
| `serializer` | `messenger.default_serializer` | Messenger serializer service id; aliased as `somework_cqrs.outbox.serializer` |
| `auto_setup` | `true` | boolean |
| `max_attempts` | `10` | integer, at least 1: attempts before the relay gives up on a row (three times as many when its transport fails) |
| `signing.enabled` | `true` | boolean (no environment variables): sign stored rows and verify them before the relay decodes them |
| `signing.secret` | `null` | string; `null` uses `kernel.secret` (`framework.secret`) |
| `signing.previous_secrets` | `[]` | list of strings: secrets whose signatures are still accepted |
| `signing.accept_unsigned` | `false` | boolean: relay rows without a signature (e.g. of an earlier version) |

Enabling the outbox registers the `SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage`
service plus the `somework:cqrs:outbox:*` commands. With `storage: null` that is
`DbalOutboxStorage`, which requires doctrine/dbal (compilation fails otherwise)
and uses `table_name`, `connection` and `auto_setup`; another storage ignores
them (see [Custom storage](outbox.md#custom-storage)).

* Use the connection that holds your business data, so storing an outbox row
  is part of the same transaction.
* With `auto_setup: true` the table is created (or upgraded with the columns
  added in 0.5.0, but not the index, which is left to `somework:cqrs:outbox:setup`) on first use, but never inside an open transaction: that throws a `LogicException` asking you to run
  `somework:cqrs:outbox:setup`. Disable `auto_setup` when migrations manage the
  table. With doctrine/orm installed, the table is also added to the schema of
  the outbox connection, so `doctrine:migrations:diff` picks it up.
* The relay decodes stored rows with `serializer`. `OutboxWriter` encodes with
  it; encode with the same serializer when you call `OutboxMessage::fromEnvelope()`
  yourself.

See [Transactional outbox](outbox.md).

```yaml
somework_cqrs:
    outbox:
        enabled: true
        connection: default
        auto_setup: false
```

## Console commands

All commands are registered automatically; the `somework:cqrs:outbox:*` commands
only when `outbox.enabled` is `true`. Exit codes follow Symfony's convention:
`0` success, `1` failure, `2` invalid input.

| Command | Arguments and options | Exit codes |
|---------|-----------------------|------------|
| `somework:cqrs:list` | `[--type=TYPE ...] [--message=TEXT] [--details]` | `0`; `2` for an unknown `--type` |
| `somework:cqrs:generate` | `<type> <name> [--handler=FQCN] [--dir=DIR] [--force]` | `0`; `1` when a file exists (without `--force`) or cannot be written; `2` for an invalid type, class name or path |
| `somework:cqrs:debug-transports` | none | `0` |
| `somework:cqrs:health` | none | `0` OK, `1` warnings, `2` critical |
| `somework:cqrs:outbox:setup` | none | `0`; `1` for a storage that does not implement `OutboxSchema`, or when the database fails; `128 + signal` when stopped by a signal |
| `somework:cqrs:outbox:relay` | `[--limit=100]` (`-l`) | `0`; `1` when a row failed, the storage failed, a signal stopped the run, or the lock could not be acquired or was lost; `2` for an invalid limit |
| `somework:cqrs:outbox:failed` | `[--requeue [--transport=NAME] [--sign]] [<id> ...] [--limit=50]` (`-l`) | `0`; `1` for a storage that does not implement `FailedOutboxMessages`, when the database fails, when a given id was not requeued, or when `--sign` refused or was not confirmed; `2` for ids without `--requeue`, `--transport` or `--sign` without ids, or an invalid limit |
| `somework:cqrs:outbox:purge` | `[--older-than="7 days"]` | `0`; `2` for an invalid age |

### somework:cqrs:list

Prints one table per message type with a row per handler and bus: the message
class, its display name when the [`naming`](#naming) strategy gives it another
name than the class name, the handler class and the bus. `--type` accepts
`command`, `query` or `event` and can be repeated; `--message` keeps the messages
whose class or display name contains the text (case-insensitive). `--details`
prints one table per handler with its service id and the configuration the
bundle resolves for the message:

* **Dispatch Mode**: the mode used for `DispatchMode::DEFAULT`.
* **Async Defers**: whether async dispatches get `DispatchAfterCurrentBusStamp`
  (`n/a` for queries).
* **Sync Transports** / **Async Transports**: transports from the
  `transports` configuration (`None` when nothing is configured, so Messenger
  routing applies; `n/a` for the async column of queries).
* **Retry Policy**, **Serializer**, **Metadata Provider**: the resolved service
  classes. A retry policy other than `NullRetryPolicy` is marked as not used while
  no transport is listed under `retry_strategy.transports`.

The details are resolved on an instance created without calling the
constructor; they show `n/a` for abstract classes and interfaces.

```bash
bin/console somework:cqrs:list --type=command --type=query --details
```

### somework:cqrs:generate

Creates a message and a handler for `<type>` (`command`, `query` or `event`)
and `<name>`, the fully-qualified message class. The handler class defaults to
`<name>Handler`; change it with `--handler`.

Files follow the PSR-4 mapping in the project's `composer.json`
(`App\Command\ShipOrder` becomes `src/Command/ShipOrder.php` for `"App\\": "src/"`); a
namespace that no prefix covers is refused unless `--dir` is given.
`--dir` is relative to the project directory and replaces the directory mapped
to the namespace prefix. Existing files are only overwritten with `--force`.
The generated handler uses the attribute and a typed `__invoke()`.

```bash
bin/console somework:cqrs:generate command 'App\Command\ShipOrder'
```

### somework:cqrs:debug-transports

Prints the `transports` configuration: the default transports and the
per-message overrides of each of the five sections. It does not show
`framework.messenger.routing` or `#[Asynchronous]` transports; inspect the
routing with `bin/console debug:config framework messenger.routing`.

### somework:cqrs:health

Instantiates every CQRS handler service and every Messenger transport and
reports problems in a table. The exit code is the highest severity found. See
[Production: health checks](production.md#health-checks) for details and custom
checks.

### Outbox commands

* `somework:cqrs:outbox:setup` creates the outbox table if it does not exist,
  and adds the columns and indexes a table of an earlier version lacks (the
  automatic setup only adds the columns).
* `somework:cqrs:outbox:relay` sends due rows (transports take turns; new rows
  first, then retries) and
  marks them published. A row that fails is retried later (1 minute, doubling up
  to 1 hour) and makes the command exit with `1`; after `max_attempts` attempts
  (three times as many for transport failures) it is given up. A transport that
  fails 3 times in a row (10 times, or 3 over 10 seconds, after a successful
  send) is paused until the next run.
* `somework:cqrs:outbox:failed` lists the given-up rows with their last error;
  `--requeue` hands all of them, or the given ids, back to the relay, to another
  transport with `--transport`. A row stored for a transport that does not exist
  is given up on its first run.
* `somework:cqrs:outbox:purge` deletes rows published before the given age.

See [Production: outbox operations](production.md#outbox-operations).

## Handler registry service

`SomeWork\CqrsBundle\Registry\HandlerRegistry` exposes the handler map compiled
into the container (it backs `somework:cqrs:list` and the health check). It is
part of the public API (`@api`); get it from the container (autowire
`HandlerRegistry`) rather than constructing it.

* `all()` returns every handler as a list of `HandlerDescriptor` objects
  (`type`, `messageClass`, `handlerClass`, `serviceId`, `bus`). A handler
  registered on a sync and an async bus appears once per bus.
* `byType(MessageType::Command)` (`SomeWork\CqrsBundle\Registry\MessageType`: `Command`,
  `Query`, `Event`) limits the list to one message type; `HandlerDescriptor::$type` is a
  `MessageType` too.
* `getDisplayName(HandlerDescriptor $descriptor)` returns the name produced by
  the configured naming strategy.
