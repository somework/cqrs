# Upgrade Guide

## Backward Compatibility Promise

This bundle follows [Semantic Versioning](https://semver.org/). While the major version is 0,
a minor release (0.4 → 0.5) may contain breaking changes; patch releases never do. Every
breaking change is listed in this guide and in the [changelog](CHANGELOG.md).

The promise covers:

- classes, interfaces, traits and enums annotated with `@api` in their class-level PHPDoc block,
  including parameter names (named arguments), except members marked `@internal` (the constructors
  of `CommandBus`, `QueryBus`, `EventBus`, `OutboxWriter` and `HandlerRegistry`: get them from the
  container);
- the `somework_cqrs` configuration tree;
- the documented service ids and tags: `somework_cqrs.outbox.storage`,
  `somework_cqrs.outbox.base_storage`, `somework_cqrs.outbox.dbal_storage`,
  `somework_cqrs.outbox.serializer`, `somework_cqrs.outbox.writer`,
  `somework_cqrs.exponential_backoff_retry_policy`, `somework_cqrs.dispatch_stamp_decider` and
  `somework_cqrs.health_checker`;
- the priorities of the built-in stamp deciders, console command names, options and exit codes,
  the OpenTelemetry span names and the `cqrs` log channel.

- **`@api` types**: public methods, constructor signatures and return types only change in a
  release that documents the change here.
- **`@internal` types** may change in any release. Do not extend, implement or instantiate them
  in application code. If you depend on one, open an issue so it can be promoted to the public API.

### What counts as a breaking change for `@api` types

- Removing a public method or changing its signature
- Removing a class, interface or trait
- Adding required constructor parameters
- Changing a return type to an incompatible type
- Adding or removing methods of interfaces meant to be implemented (the message and handler
  markers, the policy contracts, `StampDecider` and `MessageTypeAwareStampDecider`, the outbox
  contracts in `Contract\Outbox`, `HealthChecker`, and the bus interfaces)
- Adding methods to the classes and traits you extend or use (`CqrsTestCase`,
  `CqrsAssertionsTrait`, `EnvelopeAwareTrait`): they can clash with yours

### What is not a breaking change

- Adding optional parameters with default values
- Adding methods to final classes, adding classes or interfaces
- Adding cases to enums (`DispatchMode`, `CheckSeverity`, `MessageType`): give a `match` over them a default arm
- Bug fixes that change incorrect behaviour (they are still listed below when you may notice them)
- Adding `@api` or `@internal` annotations

### Deprecations

From 0.5 on, what a minor release removes is deprecated first (`@deprecated` and a
`trigger_deprecation('somework/cqrs-bundle', …)` notice, listed in this guide) and kept for at
least one more minor release. Patch releases only fix bugs. From 1.0, removals only happen in major
releases.

## Upgrading from 0.4.0 to 0.5.0

### Checklist

Steps 1 to 4 are one change: the new classes only exist after the update, and the configuration tree of 0.4
rejects the new options.

1. **Code**, in the same change as `composer update` (the new classes only exist after it, and the build or
   the `cache:clear` script of `composer update` fails until the code is adapted):
   - handlers extending the removed abstract handlers ([Abstract handlers removed](#abstract-handlers-removed-classes-moved));
   - imports of moved classes (`Support\StampDecider`, `Support\NullRetryPolicy`, …) and class names in the
     configuration;
   - `catch (HandlerFailedException)`, `catch (NoHandlerForMessageException)` and
     `catch (DelayedMessageHandlingException)` around `dispatchSync()`/`ask()`
     ([`dispatchSync()` and `ask()` errors](#dispatchsync-and-ask-errors));
   - `$exception->messageFqcn` (now `$messageClass`) and `HandlerRegistry::byType('command')` (now a
     `MessageType`);
   - events implementing `SequenceAware`, which need `getAggregateType()` ([Event ordering](#event-ordering));
   - custom `OutboxStorage` implementations and decorators, and imports of `Contract\OutboxStorage` (now
     `Contract\Outbox\OutboxStorage`) ([Transactional outbox](#transactional-outbox));
   - tests that read `getDispatched()` of the fake buses as arrays;
   - a `match` over `DispatchMode` without a `default` arm, which needs the new `OUTBOX` case
     ([Dispatch through the outbox](#dispatch-through-the-outbox));
   - `OutboxWriter::store()` calls outside a transaction on the outbox connection, which now throw
     ([Dispatch through the outbox](#dispatch-through-the-outbox)).
2. **Configuration**: the moved options ([Configuration shape](#configuration-shape)), per-message map keys of
   deleted classes or of another message type, `#[Asynchronous]` on queries, and `%env()%` values in
   compile-time options
   ([Environment variables](#environment-variables-in-the-configuration)).
3. **Outbox, before the deployment**:
   - 0.5 signs rows and only relays signed ones ([Signed rows](docs/outbox.md#signed-rows)). Rows stored by
     0.4 are unsigned, including those that 0.4 instances store during a rolling deployment: set
     `outbox.signing.accept_unsigned: true` for the upgrade, and remove it once those rows are relayed. Stop
     the 0.4 relay before the 0.5 relay starts;
   - check that every stored transport name exists, because 0.4 ignored it and 0.5 sends to it
     (`SELECT DISTINCT transport_name FROM somework_cqrs_outbox WHERE published_at IS NULL`);
   - make sure `framework.secret` is set (or set `outbox.signing.secret`). When you rotate it later, keep the old
     value in `outbox.signing.previous_secrets` until the rows signed with it are relayed;
   - on MySQL and MariaDB, a table that 0.4 created in a `latin1` database is still `latin1` (0.5 creates new
     tables with the connection's defaults, but does not convert existing ones), and fails to store messages
     with 4-byte characters such as emoji. Check with `SHOW CREATE TABLE somework_cqrs_outbox` and convert it
     while the relay is stopped: `ALTER TABLE somework_cqrs_outbox CONVERT TO CHARACTER SET utf8mb4 COLLATE
     utf8mb4_unicode_ci` (it copies the table and blocks writes meanwhile).
4. `composer update somework/cqrs-bundle`, committed together with steps 1 to 3.
5. **Workers, before the deployment**, with OpenTelemetry enabled: messages dispatched by 0.5 carry a
   `TraceContextStamp`, a class 0.4 does not have, so a 0.4 worker fails to decode them. Stop the 0.4 workers
   (or drain their queues) before 0.5 code dispatches, and roll back only once no message sent by 0.5 is
   queued. Messages sent by 0.4 are read by 0.5.
6. **Before the new version takes traffic**, run `bin/console somework:cqrs:outbox:setup` (or your Doctrine
   migration): writes need the new columns.
7. Start the relay and the workers; `bin/console somework:cqrs:health` shows what is still missing.

**Rolling back to 0.4** after the setup has run: stop the 0.5 relays first. 0.4 ignores the new columns, so it
relays the rows 0.5 gave up on (including rows refused for a missing or invalid signature, which then reach
`unserialize()`), ignores claims and retry times, and scans the table without the index of 0.4, which the setup
dropped. Before starting the 0.4 relay, inspect the given-up rows (`somework:cqrs:outbox:failed`) and delete or
mark as published those you do not want sent, and recreate the index of 0.4 if the table is large:
`CREATE INDEX idx_somework_cqrs_outbox_published_created ON somework_cqrs_outbox (published_at, created_at)`.

### Requirements

- Symfony 7.2 or newer, including Symfony 8.
- `psr/container`, `symfony/filesystem` and `symfony/service-contracts` are now direct dependencies
  (they were already installed through Symfony).
- Optional packages have minimum versions, declared as Composer conflicts: `doctrine/dbal` 4.0,
  `open-telemetry/api` 1.8, `symfony/lock` 7.2 and `symfony/rate-limiter` 7.2. A project with an older version
  installed (for example DBAL 3) must upgrade it first, or Composer refuses the update.

### Bundle services

The bundle no longer registers every class under `src/` as a service (0.4 loaded the whole directory, including
DTOs, exceptions and the testing fakes). Only the facades, their interface aliases, the registry, the console
commands, the health checkers and the default policies are services. If you aliased or fetched another bundle
class from the container, for example `SomeWork\CqrsBundle\Testing\FakeCommandBus` in a `when@test` block,
define that service yourself, as shown in
[Testing](docs/testing.md#swapping-the-buses-for-fakes-in-the-test-container).

### Handler interfaces are marker interfaces

`CommandHandler`, `QueryHandler` and `EventHandler` no longer declare `__invoke()`. PHP does not let an
implementation narrow a parameter type, so the untyped declaration forced handlers to accept any
message, and a typed `__invoke(CreateTask $command)` was a fatal error.

Type-hint the concrete message; the bundle routes the handler by that type:

```php
#[AsCommandHandler(CreateTask::class)]
final class CreateTaskHandler implements CommandHandler
{
    public function __invoke(CreateTask $command): mixed
    {
        // ...
        return null;
    }
}
```

Action needed only if your code calls `__invoke()` through the interface type (`CommandHandler $handler; $handler($command)`):
type against the concrete handler or a callable instead. A handler that implements an interface but has no
typed first parameter and no attribute now fails at compile time with
`Cannot determine the message handled by "..."`: add the type or the attribute.

### Abstract handlers removed, classes moved

- `AbstractCommandHandler`, `AbstractQueryHandler` and `AbstractEventHandler` are removed: PHP could not
  let their `handle()`, `fetch()` and `on()` narrow the message parameter. Implement the marker interface
  with a typed `__invoke()` instead, plus `EnvelopeAware` and `EnvelopeAwareTrait` when the handler reads
  the envelope:

  ```php
  // Before
  #[AsCommandHandler(CancelOrder::class)]
  final class CancelOrderHandler extends AbstractCommandHandler
  {
      protected function handle(Command $command): mixed { /* $this->getEnvelope() */ }
  }

  // After
  final class CancelOrderHandler implements CommandHandler, EnvelopeAware
  {
      use EnvelopeAwareTrait;

      public function __invoke(CancelOrder $command): mixed { /* $this->getEnvelope() */ }
  }
  ```

- Classes moved (update imports and class names in your configuration):

  | Before | After |
  |---|---|
  | `SomeWork\CqrsBundle\Support\StampDecider` | `SomeWork\CqrsBundle\Contract\StampDecider` |
  | `SomeWork\CqrsBundle\Support\MessageTypeAwareStampDecider` | `SomeWork\CqrsBundle\Contract\MessageTypeAwareStampDecider` |
  | `SomeWork\CqrsBundle\Support\NullRetryPolicy` | `SomeWork\CqrsBundle\Policy\NullRetryPolicy` |
  | `SomeWork\CqrsBundle\Support\ExponentialBackoffRetryPolicy` | `SomeWork\CqrsBundle\Policy\ExponentialBackoffRetryPolicy` |
  | `SomeWork\CqrsBundle\Support\NullMessageSerializer` | `SomeWork\CqrsBundle\Policy\NullMessageSerializer` |
  | `SomeWork\CqrsBundle\Support\RandomCorrelationMetadataProvider` | `SomeWork\CqrsBundle\Policy\RandomCorrelationMetadataProvider` |
  | `SomeWork\CqrsBundle\Support\ClassNameMessageNamingStrategy` | `SomeWork\CqrsBundle\Policy\ClassNameMessageNamingStrategy` |

- `HandlerRegistry::byType()` takes a `SomeWork\CqrsBundle\Registry\MessageType` (`byType(MessageType::Command)`),
  and `HandlerDescriptor::$type` is a `MessageType`.
- The exceptions expose `$messageClass` instead of `$messageFqcn`.
- The fake buses' `getDispatched()` returns `RecordedDispatch` objects (`$record->message`, `->mode`,
  `->stamps`) instead of arrays.
- `Query`, `QueryHandler`, `CommandHandler` and `EventHandler` have template defaults, so PHPStan no longer
  asks for generic types on them; declare `@implements Query<ResultType>` to get the result type from
  `QueryBusInterface::ask()`. Psalm does not support template defaults: with Psalm, declare the generics
  everywhere (`@implements Query<mixed>` for an untyped result). The marker interfaces are `@psalm-immutable`,
  so Psalm also asks for `@psalm-immutable` on message classes; `somework:cqrs:generate` adds both.

### Configuration shape

The per-message sections now share one shape: per message type a `default` and a `map`, plus a global
`default` where one makes sense (`retry_policies`, `serialization`, `metadata`, `naming`, `rate_limiting`; not
`dispatch_modes`, `transports` and `dispatch_after_current_bus`, whose global default would also hit
synchronous messages). `naming` has no `map`: display names are per type. Options of 0.4 that moved fail the
build with a message naming the new place.

| 0.4 | 0.5 |
|---|---|
| `async.dispatch_after_current_bus.<type>` | `dispatch_after_current_bus.<type>` |
| `naming.<type>: App\Naming` | `naming.<type>.default: App\Naming` |
| `transports.<section>.stamp` | removed (`transport_names` was the only value) |
| `retry_policies.<type>.default` (required, `NullRetryPolicy`) | optional; `null` falls back to the new `retry_policies.default` (`NullRetryPolicy`) |
| `rate_limiting.<type>.map` only | also `rate_limiting.default` and `rate_limiting.<type>.default` |

- A service id or rate limiter name that does not exist now fails the build with the option that names it:
  `The service "app.retry.payment" configured at "somework_cqrs.retry_policies.command.map.App\…" does not exist.`
  (before: `The service "somework_cqrs.retry.command_resolver" has a dependency on a non-existent service …`).
- A service that does not implement what its option needs (e.g. a serializer under `retry_policies`) fails the
  build with the option that names it, instead of a `TypeError` at the first dispatch.
- A `rate_limiting` `default` is applied per message class: every class gets its own bucket.
- The container no longer autowires the internal services `DispatchModeDecider`, `DispatchAfterCurrentBusDecider`,
  `TransportMappingProvider`, `CausationIdContext` and the removed `MessageTransportStampFactory` by class name.
  They were `@internal`; use the bus facades, `HandlerRegistry` or the console commands instead.

### Handlers are registered on the async buses

Handlers without an explicit `bus` are registered on the sync bus of their type **and** on the async bus of
their type (`buses.command_async`, `buses.event_async`) when one is configured. Before, they were only on the
sync bus, so a worker consuming messages sent through the async bus failed with "No handler for message".

If you worked around this by declaring the async bus explicitly (`#[AsCommandHandler(CreateTask::class, bus: 'command.async_bus')]`),
the handler now lives only on that bus, as before; you can remove the `bus` argument to register it on both.
`HandlerRegistry` and `somework:cqrs:list` report one entry per handler and bus, so such a handler appears twice.

### Compile-time handler validation per bus

`ValidateHandlerCountPass` checks commands and queries per bus: two different services handling the same
command on the same bus fail the build; the same handler on the sync and the async bus is fine. A handler
registered without a bus (for example a plain `#[AsMessageHandler]`, which Messenger puts on every bus) counts
on every bus, so a leftover Messenger handler next to a bundle handler now fails the build instead of both
running. Handlers registered for a parent class, an interface or `*` (a catch-all `__invoke(Command $command)`,
also a plain Messenger `#[AsMessageHandler]`) count for every command or query they receive on their bus. The check for messages without any handler was removed: it could not detect anything the bus does
not already report.

A handler attribute whose type contradicts the message, such as `#[AsCommandHandler(OrderPlaced::class)]` for an
event, is now a compile error; before, the handler was registered on the command bus and never called.
A handler that implements several handler interfaces and accepts a union (`CommandHandler` and `EventHandler`
with `__invoke(CreateTask|TaskCreated $message)`) is registered for each message under the type that matches it.
A union member whose type matches none of the handler's interfaces (a `CommandHandler` that also accepts an
event) is a compile error: implement the matching interface, or split the handler.

### `#[Asynchronous]` is honoured for default dispatch

A message class carrying `#[Asynchronous]` now goes to the async bus when dispatched with
`DispatchMode::DEFAULT` (the default). Resolution order: an exact entry in `dispatch_modes.<type>.map`, then
the attribute, then entries for parent classes or interfaces in the map, then `dispatch_modes.<type>.default`.
An explicit `DispatchMode::SYNC` still dispatches synchronously.

### Stamps passed by the caller win

The stamp pipeline no longer replaces or duplicates stamps you pass to `dispatch()`/`ask()`:
`MessageMetadataStamp`, `SerializerStamp`, `AggregateSequenceStamp`, `DeduplicateStamp` and
`DispatchAfterCurrentBusStamp` are kept as given. The causation id is written into the last
`MessageMetadataStamp` and an explicit causation id is kept. `IdempotencyStamp` stays on the envelope next to
the `DeduplicateStamp` it produces.

### Correlation and causation ids

`MessageMetadataStamp` gains a message id (`getMessageId()`, fourth constructor argument, generated when
omitted), and the ids mean what their names say:

- A message dispatched while another one is handled inherits the correlation id of the handled message. In
  0.4 every message got a new random correlation id, which in practice identified the message.
- Its causation id is the **message id** of the handled message (it was the handled message's correlation id).
- `createWithRandomCorrelationId()` uses the same random id as message id and correlation id.

If your logs or projections used the correlation id to identify a single message, use `getMessageId()`. Stamps
serialized by 0.4 with Messenger's PHP serializer (messages in a queue or the outbox) are read with their
correlation id as message id; with the Symfony serializer (JSON), they get a new message id each time they are
decoded. Create one stamp per dispatch: a stamp passed to several dispatches gives them the same message id
(a provider must return a new stamp for each call). With `causation_id` enabled (the default), forwarding the
handled message's own stamp to a child (for example `$received->withExtra('tenant', $id)`) is recognised: the child gets a new message id, keeps the
correlation id and names the handled message as its cause.

### `dispatchSync()` and `ask()` errors

- When exactly one handler fails, its exception is rethrown as is instead of Messenger's
  `HandlerFailedException`. Update `catch (HandlerFailedException $e)` blocks around these two methods.
- `MessageSentToTransportException` (new, `@api`) replaces the misleading `NoHandlerException` when the
  message was routed to a transport instead of being handled.
- `DuplicateMessageException` (new, `@api`) is thrown when idempotency deduplication dropped the message.
- `DispatchAfterCurrentBusStamp` is ignored so the result is available immediately.
- `dispatchSync()` throws `MultipleHandlersException` when more than one handler ran, like `ask()`; before, it
  returned the result of the last handler.
- A missing handler raises the bundle's `NoHandlerException` (with Messenger's `NoHandlerForMessageException` as
  previous exception) instead of Messenger's exception. Both extend `\LogicException`; update
  `catch (NoHandlerForMessageException $e)` blocks around these two methods.
- The async bus is checked before the stamp pipeline runs, so a dispatch failing with
  `AsyncBusNotConfiguredException` no longer consumes a rate-limiter token.
- `DeferredDispatchFailedException` (new, `@api`) replaces Messenger's `DelayedMessageHandlingException` when the
  handler succeeded but a message it deferred with `DispatchAfterCurrentBusStamp` (by default: asynchronous events)
  failed afterwards. `$result` holds the handler's result; the handler's work stays done, so do not retry the command.
  Update `catch (DelayedMessageHandlingException $e)` blocks around these two methods.

### Dispatch through the outbox

- **Breaking:** `DispatchMode` has a new case, `OUTBOX`. A `match` over `DispatchMode` without a `default` arm
  fails with `UnhandledMatchError` when it meets it: in bus decorators or wrappers implementing
  `CommandBusInterface`/`EventBusInterface`, and in tests reading `RecordedDispatch::$mode` of the fake buses.
  Stamp deciders, retry policies, serializers and metadata providers never see it: a message dispatched through
  the outbox has its stamps decided as an `ASYNC` dispatch, so they cannot tell the two apart.
- **Breaking:** `OutboxWriter::store()` refuses to store outside a transaction on the outbox connection
  (`OutboxRequiresTransactionException`) unless `outbox.require_transaction: false` is set. A connection with
  `auto_commit: false` counts as being in a transaction.
- `OutboxWriter::store()` refuses a transport that is not a Messenger transport (`UnknownOutboxTransportException`)
  instead of storing a row the relay gives up on.
- With the outbox enabled, the bundle's outbox middleware sits right after Messenger's
  `add_default_stamps_middleware` and right before `send_message` on the CQRS buses, and wraps Doctrine's
  `doctrine_transaction` and `doctrine_open_transaction_logger` there. It only acts on messages dispatched through
  the outbox: the middleware before the store runs when they are stored (except those two Doctrine middleware),
  and again when the relay sends them (the stamps it adds again give way to the stored ones). Middleware must call
  the next middleware for such a dispatch. The relay's run has no caller context and no `ReceivedStamp`: middleware
  that checks the dispatching context (authorization) should skip envelopes with `RelayedFromOutboxStamp`.
- With the outbox enabled, `transports.command_async`/`event_async` no longer require an async bus: the outbox
  stores its rows for them.

### Event ordering

- **Breaking:** `SequenceAware` has a new method, `getAggregateType(): string`. Return the same value for every event
  of an aggregate (e.g. `'order'`). `AggregateSequenceStamp::$aggregateType` now holds it; before, it held the class
  of each event, so consumers keeping one sequence per `aggregateType` and id saw one sequence per event class.
  An empty aggregate type is rejected.
- Messages queued (or stored in the outbox) by 0.4 still carry the event class as `aggregateType`. A consumer
  that keeps the last sequence number per aggregate type and id sees a new stream after the deployment: drain
  the queues and the outbox before it, or map the old event classes to the new aggregate type in the consumer,
  and re-key the sequence numbers it stored.

### Stamp decider priorities and resolution

- `DispatchAfterCurrentBusStampDecider` runs at priority -10 instead of 0, so custom deciders with a priority
  between -10 and 0 now run before it (and see no `DispatchAfterCurrentBusStamp` yet).
- Retry policies, serializers, metadata providers and rate limiters are resolved once per message class and
  reused: they must be stateless, and a service defined as not shared is reused too.

### Log channel

With MonologBundle, the bundle logs on its own `cqrs` channel. Handlers that filter by channel (e.g.
`channels: ['!event']` or `['app']`) need the `cqrs` channel added or excluded.

### Middleware order and OpenTelemetry

When `buses.command`, `buses.query` and `buses.event` are all configured, the Messenger default bus is no longer
treated as a CQRS bus: it gets none of the bundle middleware (useful when it serves the mailer or notifier).

The bundle middleware (causation id, OpenTelemetry, allow-no-handler for events) is inserted right after
Messenger's `dispatch_after_current_bus` middleware instead of at the top of the stack, so messages deferred until
the current bus finishes pass through it too. The deduplication lock release sits right after Messenger's
`deduplicate_middleware`.

OpenTelemetry now creates one span per pass: `cqrs.dispatch <Message>` (kind PRODUCER) when dispatching and
`cqrs.consume <Message>` (kind CONSUMER) when a worker handles a received message, linked through the new
`TraceContextStamp`. A small middleware at the top of each bus captures the trace context at dispatch time, so
messages deferred until the current handler finishes stay in its trace. Update dashboards or alerts that
matched the previous span names.

### `#[Asynchronous]` and configured transports

The transport of an asynchronous dispatch is now chosen in this order: a `TransportNamesStamp` passed by the
caller, an entry for exactly the message class in `transports.command_async.map` / `transports.event_async.map`,
the attribute's `transport`, entries for parent classes or interfaces, the section's `default`. A bare
`#[Asynchronous]` only falls back to the `async` transport when nothing is configured and
neither `framework.messenger.routing` nor `#[AsMessage(transport: ...)]` routes the message; before, it overrode both the configuration and
Messenger's routing.

For messages with a handler in the application, the container compilation now checks the attribute: the async
bus of the message type must be configured, a named transport must exist, and a bare attribute needs the `async`
transport, a `transports.command_async` / `transports.event_async` entry or a `framework.messenger.routing`
route. Before, these mistakes surfaced at the first dispatch.

### Environment variables in the configuration

Options the container compilation needs (dispatch modes, transport names, bus ids, service ids,
`retry_strategy.transports`, and `outbox.table_name`, `outbox.connection` and `outbox.serializer`) reject
`%env(...)%` with a clear message; before, they failed with
"Incompatible use of dynamic environment variables" or an invalid enum value. Environment variables still work in
`retry_strategy.jitter`, `retry_strategy.max_delay`, `idempotency.ttl`, `outbox.auto_setup`, `outbox.max_attempts`,
`outbox.signing.secret`, `outbox.signing.previous_secrets`, `outbox.signing.accept_unsigned` and the
`dispatch_after_current_bus` flags.

### Handler attributes must match the handler method

`#[AsCommandHandler(ShipOrder::class)]` on a handler whose `__invoke()` accepts another message is now a compile
error; before, every dispatch failed with a `TypeError`. A query handler whose method is declared `: void` or
`: never` is a compile error too; before, `ask()` returned `null`.

### Per-message configuration through interfaces

When a message implements several interfaces that have map entries (retry policies, serializers, metadata,
transports, dispatch-after-current-bus, rate limiters), the most specific interface wins, whatever the order
in which the class declares them, the same rule the dispatch mode already used.

### Retry policy stamps

Stamps returned by a `RetryPolicy` no longer override a stamp of the same class passed by the caller
(for example a `DelayStamp`).

### Retry strategy

- Without a transport-level fallback strategy, `CqrsRetryStrategy` now uses Messenger's
  `MultiplierRetryStrategy` defaults (3 retries, 1 s delay, multiplier 2) for messages without a
  `RetryConfiguration` policy. Before, such messages were retried forever without delay.
- Delays are capped by `max_delay` before and after jitter.
- `retry_strategy.transports` keys are no longer normalised (`my-transport` stays `my-transport`) and must be
  existing Messenger transports.
- Each message received from such a transport uses the `retry_policies` section of its own type. In 0.4 every
  message used the section the transport was mapped to (events on a transport mapped to `command` got the
  command policies).

### Configuration validation

The container build now fails for configuration that used to be silently ignored or to fail later:

- Service ids (policies, providers, serializers, naming strategies, buses) must be non-empty strings.
- Keys of every per-message `map` must be existing classes or interfaces. A leading backslash is removed.
  Remove entries for classes that no longer exist. A key of another message type (a query or an event
  under a `command` map) never matched and is now an error.
- `#[Asynchronous]` on a query is an error: queries are always handled synchronously.
- `causation_id.buses` entries must be existing bus services (aliases are resolved), and so must
  `default_bus` when a command, query or event bus is not configured.
- The `enabled` flags of `outbox`, `outbox.signing`, `idempotency`, `causation_id`, `sequence` and
  `rate_limiting` decide which services are registered and can no longer use `%env()%`.
- Rate limiting is inactive while no limiter is configured; configuring a limiter without symfony/rate-limiter
  installed is an error instead of a silent no-op.

### Idempotency

Deduplication needs symfony/messenger 7.3+, symfony/lock and the lock component enabled (`framework.lock`) so
Messenger registers its deduplicate middleware. The container compilation log now says which piece is missing.
A failed synchronous dispatch releases the idempotency lock, so the message can be retried before the TTL expires.

### Transactional outbox

- **The table gains seven columns** (`attempts`, `available_at`, `failed_at`, `last_error`, `claim_token`,
  `claimed_at`, `signature`) **and two indexes** (`idx_<table>_pending`, which replaces
  `idx_<table>_published_created`, and `idx_<table>_claimed`). **Writes need the columns**: run
  `bin/console somework:cqrs:outbox:setup` (or your migration) before the new version takes traffic (checklist
  step 6), over a direct connection, not through PgBouncer in transaction mode. Without it, a write inside a
  transaction fails with `The outbox table "…" lacks columns this version of the bundle needs (…)`; outside one,
  `auto_setup` adds the columns first, but never the indexes. On a large table, purge the published rows first.
  The details (locks, timeouts, `CREATE INDEX CONCURRENTLY`, the SQL for a migration of your own) are in
  [Upgrading from 0.4](docs/outbox.md#upgrading-from-04). Stop the 0.4 relays before the new version runs: they
  ignore retry times, given-up rows and claims.
- The relay lock expires after 60 seconds (it was the lock factory's default, 300 seconds) and is extended every
  10 seconds; a relay killed without cleanup blocks the next runs for at most a minute.
- `OutboxWriter::store()` called while a handler runs adds a `MessageMetadataStamp` that continues the flow of the
  handled message, unless you pass one.
- Each application needs its own outbox table: a relay gives up the rows of another application sharing its table
  (other secret, unknown transports).
- `OutboxStorage` is now `@api`, moved to `SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage`, and changed (see
  [Custom storage](docs/outbox.md#custom-storage)). Custom implementations must:
  - change `fetchUnpublished(int $limit)` to `fetchUnpublished(int $limit, array $excludedTransports = [])`: it
    returns only due messages (unpublished, not given up, retry time passed), the transports taking turns and,
    within a transport, first those never attempted in the order they were stored, then the others in the order
    of their retry time; it skips the messages of the excluded transports (`null` stands for messages without a
    transport name);
  - add `claim(array $messages, array $retryAt, string $token): array`, which counts an attempt and postpones
    each fetched message atomically while its attempts and transport are unchanged, and returns the claimed ids;
    `renew(array $messages, array $retryAt, string $token): array`, which extends the claims still held;
    `release(array $messages, string $token): void`, which undoes the claims of unattempted messages; and
    `recordFailure(string $id, string $token, int $attempts, string $error, ?DateTimeImmutable $retryAt): bool`;
  - change `markPublished(string $id)` to `markPublished(array $ids)`, which ignores unknown or published ids;
  - add `purgePublished(DateTimeImmutable $publishedBefore): int`;
  - return the stored state with each message: `OutboxMessage` has the new properties `attempts`, `lastError`,
    `claimedAt`, `availableAt` and `signature` (constructor arguments after `$transportName`, all optional).
    Ids are lowercased.

  A decorator of `somework_cqrs.outbox.storage` needs the same methods; `setup`, `failed` and the health
  check keep using the storage behind it.
- The table is never created inside an open database transaction; `store()` then throws a `LogicException`
  that tells you to create it first. Run `bin/console somework:cqrs:outbox:setup` once per environment, use
  Doctrine migrations (with doctrine/orm installed the table is added to generated migrations for the
  configured connection), or set `outbox.auto_setup: false` when migrations own the table.
- New options: `outbox.connection` (DBAL connection name, default `default`), `outbox.serializer`
  (default `messenger.default_serializer`), `outbox.auto_setup` (default `true`) and `outbox.max_attempts`
  (default `10`).
- Build rows with `OutboxMessage::fromEnvelope($envelope, $serializer, 'transport')`: ids are time-ordered UUIDv7;
  the constructor rejects empty ids and bodies.
- The relay dispatches each message on the bus of its type (`buses.command_async`, else `buses.command`, for
  commands; `buses.event_async`, else `buses.event`, for events; the default bus otherwise), so workers route it
  to the bus that has its handlers. A `BusNameStamp` stored with the envelope is kept.
- The relay sends each message to its stored transport and runs as a single instance when symfony/lock is
  installed. A row that fails is postponed (1 minute, doubling up to 1 hour) instead of being retried on every
  run, and given up after `outbox.max_attempts` attempts; list and requeue given-up rows with the new
  `somework:cqrs:outbox:failed` command. Every attempt is claimed before the message is sent, so a row that
  crashes the relay process is retried on its own and not forever, and overlapping relays skip each other's rows.
  Sent rows are marked as published every 2 seconds and at the end of the run: when the relay dies, the rows of
  the last 2 seconds are sent again. Rows whose
  transport fails (`TransportException`) get three times `outbox.max_attempts`, and a transport that fails 3
  times in a row is paused until the next run while the other transports are relayed. Rows are relayed in the
  order: the transports take turns; within one, new rows first, in the order they were stored, then the rows due
  for a retry. The relay exits
  with code 1 when any row failed, the storage failed or a signal (SIGTERM, SIGINT) stopped it after the current
  row (monitor the exit code, or the new outbox check of `somework:cqrs:health`); an invalid `--limit` now exits
  with 2 instead of 1. `--limit` counts processed rows, failed ones included. The relay logs failures to the
  `logger` service.
- The relay lock is named after `framework.cache.prefix_seed` when you set it (the project directory
  otherwise), the connection and the table. If every release is deployed to a new directory, set `prefix_seed` to a stable value so the
  relays of two releases cannot run at the same time.
- Dates are now stored in UTC. Rows written by earlier versions keep the local time they were written in;
  this only matters for the relay order, the purge cut-off and the ages the health check reports for rows written in
  the last hours before the upgrade (west of UTC they look older: a backlog of 0.4 rows can be reported as critical
  right after the deploy).
- `OutboxMessage`, `OutboxStorage` and `DbalOutboxStorage` are now `@api`.
- `outbox.table_name` must be a plain or schema-qualified identifier (letters, digits, underscores) and not a
  word reserved in MySQL, MariaDB, PostgreSQL or SQLite (`order`, `user`, …), and
  `somework:cqrs:outbox:purge --older-than` accepts only `<number> <unit>` with at most 6 digits (e.g. `7 days`).
- Remove old rows with `bin/console somework:cqrs:outbox:purge --older-than="7 days"`.
- With very long table names the new index is named `idx_<hash>_pending`.
- **Reads go to the primary.** With a `PrimaryReadReplicaConnection`, the relay, the health check and the
  outbox commands switch the connection to the primary before reading (0.4 read from a replica, where rows
  already published could look pending). Later reads of the application through that connection go to the
  primary too, as after any write.
- **Rows are signed** (`outbox.signing`, on by default, with `framework.secret`): the relay gives up rows
  without a valid signature without decoding them. Store rows through the `OutboxStorage` service or
  `OutboxWriter` (the signature is added by a decorator of `somework_cqrs.outbox.storage`), not through SQL; the
  autowiring alias of `DbalOutboxStorage` is gone (type-hint `OutboxSchema`, `FailedOutboxMessages` or
  `OutboxMonitoring` for the table operations). Rows of 0.4 are unsigned: see step 3 of the checklist. An empty
  `framework.secret` makes every service that stores rows fail to start. Disable signing with
  `outbox.signing.enabled: false` to keep trusting the table as before.
- A storage of your own is configured with `outbox.storage: App\Outbox\MyStorage` instead of redefining the
  `somework_cqrs.outbox.storage` service (which still works), and no longer needs doctrine/dbal. The setup and
  failed commands and the health check use it when it implements `SomeWork\CqrsBundle\Contract\Outbox\OutboxSchema`,
  `FailedOutboxMessages` or `OutboxMonitoring` (see [Custom storage](docs/outbox.md#custom-storage)).
- Code that calls `DbalOutboxStorage::status()` or `fetchFailed()` gets an `OutboxStatus` and `FailedOutboxMessage`
  objects instead of arrays (`$status->oldestDue` instead of `$status['oldest_due']`). `fetchFailed()` reads the
  bodies (`bodyClass`, `bodyClasses`, `digest`) only for the messages asked for by id.
- `outbox:failed --requeue --sign` refuses a row whose message is not a command, query or event, whose body
  instantiates a class that is neither the envelope, a stamp, the message class nor a type declared by their
  properties, or that uses custom serialization (`Serializable`). A message of yours that is not a command, query or
  event, or has an object in an untyped property (`mixed`, `object`, arrays), needs `--allow-class=<class or
  interface>` to be signed.

### Console commands

- `somework:cqrs:health` checks all handlers and every Messenger transport by instantiating them. Before, it
  reported every handler and transport as CRITICAL and always exited with 2; probes that relied on that
  now pass.
- `somework:cqrs:generate` places files according to the PSR-4 mapping of your `composer.json`
  (`App\Command\ShipOrder` → `src/Command/ShipOrder.php`, previously `src/App/Command/ShipOrder.php`). `--dir`
  is resolved against the project directory and replaces the directory mapped to the namespace prefix; a namespace
  that no prefix covers is refused unless `--dir` is given.
  Handlers are generated with the attribute and a typed `__invoke()`. Invalid input exits with code 2.
- `somework:cqrs:outbox:failed` lists the message class (from the serializer's `type` header) and gains `--sign`
  (with `--requeue` and ids) to sign rows you checked.
- `somework:cqrs:list --type=<unknown>` exits with code 2. Without `--details` it prints one compact table per
  message type (message class, handler, bus); `--details` keeps one table per handler.

### Testing helpers

`FakeQueryBus` returns a configured `null` result instead of falling back, and the fake buses return envelopes
carrying the stamps passed to them. `getDispatched()` returns `SomeWork\CqrsBundle\Testing\RecordedDispatch`
objects (`$record->message`, `->mode`, `->stamps`) instead of arrays. New: `FakeCommandBus::willReturnFor()` and
`willThrow()` on the command and query fakes.

## Upgrading from 0.3.0 to 0.4.0

### Bus interfaces

`CommandBusInterface`, `QueryBusInterface` and `EventBusInterface` (`SomeWork\CqrsBundle\Contract`) are autowired
to the real buses and implemented by the fakes. Type-hint them instead of the concrete buses:

```diff
- public function __construct(private readonly CommandBus $commandBus) {}
+ public function __construct(private readonly CommandBusInterface $commandBus) {}
```

Replace them with the fakes in the test environment:

```yaml
when@test:
    services:
        SomeWork\CqrsBundle\Contract\CommandBusInterface:
            class: SomeWork\CqrsBundle\Testing\FakeCommandBus
            public: true
```

### Handler `__invoke()` signature

The handler interfaces stopped typing the `__invoke()` parameter in 0.4.0. In 0.5.0 they declare no method at
all; see [above](#handler-interfaces-are-marker-interfaces).

### New in 0.4.0

Attribute-only handlers, the `#[Asynchronous]` attribute, the OpenTelemetry bridge and the Symfony Flex recipe
are additive and need no migration.

## Upgrading from 0.2.x to 0.3.0

0.3.0 was a large feature release (earlier revisions of this file described it as versions "1.0" to "3.0",
which were never tagged).

### Requirements

- Symfony 7.2 or newer; Symfony 6.4 is no longer supported.

### Exceptions

Dedicated exceptions replace generic `RuntimeException`s: `NoHandlerException` (no handler for a command or
query), `MultipleHandlersException` (more than one handler for a query) and `AsyncBusNotConfiguredException`
(async dispatch without an async bus). They are `@api` and expose `$messageFqcn`, `$busName` and, where
relevant, `$handlerCount`.

### Compile-time validation

Commands and queries must have exactly one handler per bus; violations fail the container build.

### New features

Opt-in or inert by default, no migration needed: testing helpers (`SomeWork\CqrsBundle\Testing`),
`CausationIdMiddleware` (`causation_id`), `IdempotencyStamp` (`idempotency`), per-message retry through
`CqrsRetryStrategy` (`retry_strategy`), `somework:cqrs:health`, event ordering via `SequenceAware`
(`sequence`), rate limiting (`rate_limiting`) and the transactional outbox (`outbox`). See the
[documentation](https://somework.github.io/cqrs/) for each feature.

`ExponentialBackoffRetryPolicy` does not add a `DelayStamp` at dispatch time: retry delays are applied by
`CqrsRetryStrategy` when a transport retries a failed message. If you need a delay on the first dispatch,
pass a `DelayStamp` yourself or implement a `RetryPolicy` that returns one from `getStamps()`.
