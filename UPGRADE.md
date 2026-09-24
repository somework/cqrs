# Upgrade Guide

## Backward Compatibility Promise

This bundle follows [Semantic Versioning](https://semver.org/). While the major version is 0,
a minor release (0.4 → 0.5) may contain breaking changes; patch releases never do. Every
breaking change is listed in this guide and in the [changelog](CHANGELOG.md).

The promise applies only to classes, interfaces, traits and enums annotated with `@api` in
their class-level PHPDoc block.

- **`@api` types**: public methods, constructor signatures and return types only change in a
  release that documents the change here.
- **`@internal` types** may change in any release. Do not extend, implement or instantiate them
  in application code. If you depend on one, open an issue so it can be promoted to the public API.

### What counts as a breaking change for `@api` types

- Removing a public method or changing its signature
- Removing a class, interface or trait
- Adding required constructor parameters
- Changing a return type to an incompatible type
- Adding or removing interface methods

### What is not a breaking change

- Adding optional parameters with default values
- Adding methods to classes, adding classes or interfaces
- Bug fixes that change incorrect behaviour (they are still listed below when you may notice them)
- Adding `@api` or `@internal` annotations

## Upgrading from 0.4.0 to 0.5.0

### Requirements

- Symfony 7.2 or newer, including Symfony 8.
- `psr/container`, `symfony/filesystem` and `symfony/service-contracts` are now direct dependencies
  (they were already installed through Symfony).

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

### Handlers are registered on the async buses

Handlers without an explicit `bus` are registered on the sync bus of their type **and** on the async bus of
their type (`buses.command_async`, `buses.event_async`) when one is configured. Before, they were only on the
sync bus, so a worker consuming messages sent through the async bus failed with "No handler for message".

If you worked around this by declaring the async bus explicitly (`#[AsCommandHandler(CreateTask::class, bus: 'command.async_bus')]`),
the handler now lives only on that bus, as before; you can remove the `bus` argument to register it on both.

### Compile-time handler validation per bus

`ValidateHandlerCountPass` checks commands and queries per bus: two different services handling the same
command on the same bus fail the build; the same handler on the sync and the async bus is fine. A handler
registered without a bus (for example a plain `#[AsMessageHandler]`, which Messenger puts on every bus) counts
on every bus, so a leftover Messenger handler next to a bundle handler now fails the build instead of both
running. The check for messages without any handler was removed: it could not detect anything the bus does
not already report.

A handler attribute whose type contradicts the message, such as `#[AsCommandHandler(OrderPlaced::class)]` for an
event, is now a compile error; before, the handler was registered on the command bus and never called.

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

### `dispatchSync()` and `ask()` errors

- When exactly one handler fails, its exception is rethrown as is instead of Messenger's
  `HandlerFailedException`. Update `catch (HandlerFailedException $e)` blocks around these two methods.
- `MessageSentToTransportException` (new, `@api`) replaces the misleading `NoHandlerException` when the
  message was routed to a transport instead of being handled.
- `DuplicateMessageException` (new, `@api`) is thrown when idempotency deduplication dropped the message.
- `DispatchAfterCurrentBusStamp` is ignored so the result is available immediately.
- A missing handler raises the bundle's `NoHandlerException` (with Messenger's `NoHandlerForMessageException` as
  previous exception) instead of Messenger's exception. Both extend `\LogicException`; update
  `catch (NoHandlerForMessageException $e)` blocks around these two methods.
- The async bus is checked before the stamp pipeline runs, so a dispatch failing with
  `AsyncBusNotConfiguredException` no longer consumes a rate-limiter token.

### Middleware order and OpenTelemetry

When `buses.command`, `buses.query` and `buses.event` are all configured, the Messenger default bus is no longer
treated as a CQRS bus: it gets none of the bundle middleware (useful when it serves the mailer or notifier).

The bundle middleware (causation id, OpenTelemetry, allow-no-handler for events, deduplication lock release)
is inserted right after Messenger's `dispatch_after_current_bus` middleware instead of at the top of the stack,
so messages deferred until the current bus finishes pass through it too.

OpenTelemetry now creates one span per pass: `cqrs.dispatch <Message>` (kind PRODUCER) when dispatching and
`cqrs.consume <Message>` (kind CONSUMER) when a worker handles a received message, linked through the new
`TraceContextStamp`. A small middleware at the top of each bus captures the trace context at dispatch time, so
messages deferred until the current handler finishes stay in its trace. Update dashboards or alerts that
matched the previous span names.

### `#[Asynchronous]` and configured transports

The attribute's transport (default `async`) is now only used when `transports.command_async` /
`transports.event_async` configure no transport for the message; before, the attribute won over the
configuration. Stamps passed by the caller still win over both.

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

### Configuration validation

The container build now fails for configuration that used to be silently ignored or to fail later:

- Service ids (policies, providers, serializers, naming strategies, buses) must be non-empty strings.
- Keys of every per-message `map` must be existing classes or interfaces. A leading backslash is removed.
  Remove entries for classes that no longer exist.
- `causation_id.buses` entries must be existing bus services (aliases are resolved).
- The `enabled` flags of `outbox`, `idempotency`, `causation_id`, `sequence` and `rate_limiting` decide which
  services are registered and can no longer use `%env()%`.
- Rate limiting is inactive while no limiter is mapped; mapping a limiter without symfony/rate-limiter
  installed is an error instead of a silent no-op.

### Idempotency

Deduplication needs symfony/messenger 7.3+, symfony/lock and the lock component enabled (`framework.lock`) so
Messenger registers its deduplicate middleware. The container compilation log now says which piece is missing.
A failed synchronous dispatch releases the idempotency lock, so the message can be retried before the TTL expires.

### Transactional outbox

- `OutboxStorage` (still `@internal`) gained `fetchUnpublished(int $limit, int $offset = 0)` and
  `purgePublished(DateTimeImmutable $publishedBefore): int`. Custom implementations must add them.
- The table is never created inside an open database transaction; `store()` then throws a `LogicException`
  that tells you to create it first. Run `bin/console somework:cqrs:outbox:setup` once per environment, use
  Doctrine migrations (with doctrine/orm installed the table is added to generated migrations for the
  configured connection), or set `outbox.auto_setup: false` when migrations own the table.
- New options: `outbox.connection` (DBAL connection name, default `default`), `outbox.serializer`
  (default `messenger.default_serializer`) and `outbox.auto_setup` (default `true`).
- Build rows with `OutboxMessage::fromEnvelope($envelope, $serializer, 'transport')`: ids are time-ordered UUIDv7;
  the constructor rejects empty ids and bodies.
- The relay dispatches each message on the bus of its type (`buses.command_async`, else `buses.command`, for
  commands; `buses.event_async`, else `buses.event`, for events; the default bus otherwise), so workers route it
  to the bus that has its handlers. A `BusNameStamp` stored with the envelope is kept.
- The relay sends each message to its stored transport, runs as a single instance when symfony/lock is
  installed, skips rows that fail, stops after 5 consecutive send failures and exits with code 1 when any row
  failed (monitor the exit code).
- Dates are now stored in UTC. Rows written by earlier versions keep the local time they were written in;
  this only matters for the relay order and the purge cut-off of rows written in the last hours before the upgrade.
- `OutboxMessage` and `OutboxStorage` are now `@api`.
- `outbox.table_name` must be a plain or schema-qualified identifier (letters, digits, underscores), and
  `somework:cqrs:outbox:purge --older-than` accepts only `<number> <unit>` (e.g. `7 days`).
- Remove old rows with `bin/console somework:cqrs:outbox:purge --older-than="7 days"`.
- Tables created by earlier versions keep working. With very long table names the index is now named
  `idx_<hash>_published_created`; generated migrations may propose renaming it.

### Console commands

- `somework:cqrs:health` checks all handlers and every Messenger transport by instantiating them. Before, it
  reported every handler and transport as CRITICAL and always exited with 2; probes that relied on that
  now pass.
- `somework:cqrs:generate` places files according to the PSR-4 mapping of your `composer.json`
  (`App\Command\ShipOrder` → `src/Command/ShipOrder.php`, previously `src/App/Command/ShipOrder.php`). `--dir`
  is resolved against the project directory and replaces the directory mapped to the namespace prefix.
  Handlers are generated with the attribute and a typed `__invoke()`. Invalid input exits with code 2.
- `somework:cqrs:list --type=<unknown>` exits with code 2.

### Testing helpers

`FakeQueryBus` returns a configured `null` result instead of falling back, and the fake buses return envelopes
carrying the stamps passed to them.

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
