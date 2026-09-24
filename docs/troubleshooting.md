# Troubleshooting

This guide lists common problems, the exact errors they produce, and how to fix
them. Most configuration mistakes are reported when the container is compiled
(`cache:clear`, the first request in `dev`, or `lint:container`); runtime
problems appear when a message is dispatched or handled.

## Handler registration

### No handler for a message

**Symptom.** Dispatching or handling a message fails with Messenger's error:

```
Symfony\Component\Messenger\Exception\NoHandlerForMessageException: No handler for message "App\Application\Command\CreateTask".
```

On a bus with `default_middleware.allow_no_handlers: true`, `dispatchSync()`
and `ask()` report the same problem as
`NoHandlerException: No handler found for "App\Application\Command\CreateTask" dispatched on the command bus.`
Missing handlers are not detected at compile time; only duplicate handlers are.
Events without handlers are not an error.

**Cause.**

1. The handler class is not a service, for example because it is outside the
   `resource` directory of `config/services.yaml` or excluded from it.
2. The service is not registered as a handler: it has neither
   `#[AsCommandHandler]` / `#[AsQueryHandler]` / `#[AsEventHandler]` nor a
   marker interface (`CommandHandler`, `QueryHandler`, `EventHandler`), or
   autoconfiguration is disabled for it.
3. The handler is registered on another bus. A handler with an explicit `bus`
   is only registered on that bus, so a worker consuming the async bus does not
   find it, and neither does a facade using a different bus.

**Fix.**

1. Make sure the handler is an autoconfigured service and carries the attribute
   (or implements the marker interface with a typed `__invoke()`):

   ```php
   <?php

   use SomeWork\CqrsBundle\Attribute\AsCommandHandler;

   #[AsCommandHandler(CreateTask::class)]
   final class CreateTaskHandler
   {
       public function __invoke(CreateTask $command): mixed
       {
           // ...
           return null;
       }
   }
   ```

2. Check where it is registered. `somework:cqrs:list` shows one entry per
   handler and bus, and `debug:messenger` shows Messenger's view:

   ```bash
   bin/console somework:cqrs:list --type=command
   bin/console debug:messenger
   ```

3. Leave out `bus` unless you need it: handlers without it are registered on the
   sync bus of their type and on the async bus when one is configured. If you
   set `bus`, repeat the attribute for every bus the message is dispatched on.

### "Cannot determine the message handled by ..."

**Symptom.** The container compilation fails:

```
Cannot determine the message handled by "App\Application\Command\CreateTaskHandler" (service "App\Application\Command\CreateTaskHandler"). Type-hint the first parameter of App\Application\Command\CreateTaskHandler::__invoke() with the message class or declare it explicitly, e.g. #[AsCommandHandler(command: YourMessage::class)].
```

**Cause.** The handler is registered through a marker interface (or extends
`AbstractCommandHandler`, `AbstractQueryHandler` or `AbstractEventHandler`), and
the first parameter of `__invoke()` has no class type (it is untyped, `object`,
a scalar type, or missing). The bundle infers the handled message from that
type. The `Abstract*Handler` classes declare an untyped `__invoke()`, so they
always need the attribute.

**Fix.** Type-hint the message class (`public function __invoke(CreateTask $command): mixed`)
or add the attribute: `#[AsCommandHandler(CreateTask::class)]`.

### Intersection type on a handler

**Symptom.**

```
Handler "App\Application\Command\AuditHandler" type-hints the intersection "App\Domain\Auditable&App\Domain\Tenanted", which cannot be routed by Symfony Messenger. Declare the handled message explicitly (for example with the "handles"/attribute message argument).
```

**Cause.** Messenger routes messages by class or interface name, not by a
combination of them. An intersection is only accepted when one of its members
already implies all the others.

**Fix.** Declare the handled message with the attribute
(`#[AsCommandHandler(ArchiveTenantDocument::class)]`) or type-hint a single
class or interface.

### Several handlers for one command or query

**Symptom.** The container compilation fails:

```
CQRS handler validation failed (commands and queries must have exactly one handler):
Command App\Application\Command\ShipOrder has 2 handlers on bus "messenger.bus.commands": App\Application\Command\ShipOrderHandler, App\Legacy\ShipOrderHandler.
```

At runtime, `ask()` can also throw
`MultipleHandlersException: Message "App\Application\Query\FindOrder" was handled by 2 handlers on the query bus. Exactly one handler is required.`

**Cause.** Commands and queries must have exactly one handler per bus (events
may have any number). The check counts distinct services per bus; one service on
the sync and the async bus is fine. The runtime error typically comes from a
second handler the check does not attribute to that bus, such as a Messenger
`#[AsMessageHandler]` handler without a bus, which Messenger registers on every
bus.

**Fix.** Keep one handler per command or query and bus: remove the extra
handler, or register the handlers on different buses with the `bus` argument.
`somework:cqrs:list` and `debug:messenger` show all handlers of a message.

## Dispatching

### An async message runs synchronously

**Symptom.** A command or event that should go to a queue is handled during the
request, and nothing appears in the transport.

**Cause.**

1. The dispatch mode resolves to `sync`: there is no `dispatch_modes` entry or
   `#[Asynchronous]` attribute for the message, an exact-class entry says
   `sync` (it wins over the attribute), or the caller uses
   `DispatchMode::SYNC` / `dispatchSync()`.
2. The dispatch mode is `async`, but no transport is selected: no
   `transports.command_async` / `transports.event_async` entry, no
   `#[Asynchronous]` attribute and no `framework.messenger.routing` entry. The
   async bus then finds no sender and handles the message itself, because the
   handlers are registered on the async bus too.
3. The selected transport is `sync://`.

**Fix.**

```yaml
somework_cqrs:
    buses:
        command_async: messenger.bus.commands_async
    dispatch_modes:
        command:
            map:
                App\Application\Command\GenerateReport: async
    transports:
        command_async:
            default: async_commands
```

Check the result with `bin/console somework:cqrs:list --details` (the
*Dispatch Mode* and *Async Transports* columns) and
`bin/console somework:cqrs:debug-transports`.

### Async bus not configured

**Symptom.** At runtime:

```
SomeWork\CqrsBundle\Exception\AsyncBusNotConfiguredException: Asynchronous command bus is not configured. Cannot dispatch "App\Application\Command\CreateTask" in async mode.
```

or, when the configuration asks for async delivery, during compilation:

```
Asynchronous dispatch is configured for commands (the default dispatch mode is "async"), but "somework_cqrs.buses.command_async" is null. Define the Messenger bus id used for async commands before the container is compiled.
```

**Cause.** An asynchronous dispatch (`dispatchAsync()`, `DispatchMode::ASYNC`,
an `async` dispatch mode or `#[Asynchronous]`) without `buses.command_async` /
`buses.event_async`.

**Fix.** Declare the async bus in Messenger and point the bundle to it:

```yaml
framework:
    messenger:
        default_bus: messenger.bus.commands
        buses:
            messenger.bus.commands: ~
            messenger.bus.commands_async: ~

somework_cqrs:
    buses:
        command: messenger.bus.commands
        command_async: messenger.bus.commands_async
```

### `#[Asynchronous]` fails with "Invalid senders configuration"

**Symptom.**

```
Symfony\Component\Messenger\Exception\RuntimeException: Invalid senders configuration: sender "async" is not in the senders locator.
```

**Cause.** `#[Asynchronous]` without an argument sends the message to a
transport named `async`, and there is no such transport. Transport names from
the attribute are not validated at compile time.

**Fix.** Define an `async` transport, or name an existing one:
`#[Asynchronous(transport: 'async_commands')]`.

### `MessageSentToTransportException` from `dispatchSync()` or `ask()`

**Symptom.**

```
SomeWork\CqrsBundle\Exception\MessageSentToTransportException: Message "App\Application\Query\FindOrder" dispatched on the query bus was sent to transport(s) "async" instead of being handled synchronously, so no result is available. Remove it from the async routing (framework.messenger.routing / somework_cqrs.transports) or dispatch it asynchronously.
```

**Cause.** `dispatchSync()` and `ask()` need the handler result, but Messenger
sent the message to a transport. Usually a `framework.messenger.routing` entry
matches the class, one of its interfaces or `'*'`, or `transports.command` /
`transports.query` lists a transport.

**Fix.** Remove the message from that routing (route only the messages that are
meant to be asynchronous), or dispatch it asynchronously and stop expecting a
result.

### `DuplicateMessageException`

**Symptom.**

```
SomeWork\CqrsBundle\Exception\DuplicateMessageException: Message "App\Application\Command\ChargeCard" dispatched on the command bus was dropped as a duplicate (deduplication key "App\Application\Command\ChargeCard::order-42").
```

**Cause.** The dispatch carried an `IdempotencyStamp` whose key is still locked:
a synchronous dispatch with the same key succeeded less than `idempotency.ttl`
seconds ago, or an asynchronous one has not been handled by a worker yet.

**Fix.** This is the deduplication working: treat the operation as already done
(catch the exception), use a key that identifies one logical operation, or lower
`idempotency.ttl`. See [Idempotency](idempotency.md).

### `RateLimitExceededException`

**Symptom.**
`Rate limit exceeded for "App\Application\Command\SendNotification". Retry after 2026-09-24T10:15:00+00:00.`

**Cause.** The limiter mapped under `rate_limiting` has no token left. Nothing
was dispatched.

**Fix.** Catch the exception and use its `retryAfter`, `remainingTokens` and
`limit` properties (for example to answer with HTTP 429), or raise the limit in
`framework.rate_limiter`. See [Rate limiting](rate-limiting.md).

### Messages end up on the wrong bus

**Symptom.** A handler never runs, or runs on an unexpected bus.

**Cause.** `somework_cqrs.buses.*` points to different buses than the ones the
handlers are registered on (explicit `bus` arguments), or code dispatches on a
Messenger bus directly instead of through the facades.

**Fix.** Compare the *Bus* column of `bin/console somework:cqrs:list` with your
`somework_cqrs.buses` configuration. Bus ids are shown after alias resolution
(for example the real id behind `messenger.default_bus`).

## Configuration errors

These fail the container compilation.

### Unknown class in a `map`

```
Invalid configuration for path "somework_cqrs.retry_policies.command.map": "App\Application\Command\ShipOrdr" is not an existing class or interface; keys must be message class or interface names.
```

Keys of every `map` must be existing classes or interfaces. Fix the typo, or
remove entries for deleted messages. A leading backslash is allowed.

### Empty service id

```
Invalid configuration for path "somework_cqrs.buses.command": Expected a non-empty string or null, got "".
```

Service ids and names (buses, policies, serializers, providers, transport and
limiter names, outbox settings) cannot be empty or blank. Use `null` (`~`) where
the option allows it.

### Unknown service id

```
The service "somework_cqrs.retry.command_resolver" has a dependency on a non-existent service "app.retry.payment".
```

A service id under `naming`, `retry_policies`, `serialization` or `metadata`
does not exist. Define the service, or use the fully-qualified name of a
concrete class: the bundle registers such classes as services automatically.

### Environment variable in an `enabled` flag

```
"somework_cqrs.outbox.enabled" decides which services are registered when the container is compiled, so it must be a boolean and cannot use an environment variable.
```

The `enabled` flags of `outbox`, `idempotency`, `causation_id`, `sequence` and
`rate_limiting` choose which services exist. Use a literal `true`/`false`, per
environment if needed (`when@prod:` in the configuration file).

### Unknown transport name

```
Messenger transport "async_comands" configured for SomeWork CQRS is not defined.
```

A name under `somework_cqrs.transports` does not match any
`framework.messenger.transports` entry. `somework:cqrs:debug-transports` lists
the configured names.

### Unknown transport under `retry_strategy`

```
Transport "async_comands" configured under "somework_cqrs.retry_strategy.transports" is not a Messenger transport. Known transports: "async_commands", "failed".
```

Keys of `retry_strategy.transports` must be transport names, spelled exactly as
in `framework.messenger.transports`.

### Unknown bus under `causation_id.buses`

```
"somework_cqrs.causation_id.buses" contains "messenger.bus.comands", which is not a Messenger bus service id.
```

List bus service ids as declared under `framework.messenger.buses`.

### Missing optional packages

```
Rate limiters are mapped under "somework_cqrs.rate_limiting" but symfony/rate-limiter is not installed. Run "composer require symfony/rate-limiter" or remove the mappings.
```

```
Outbox is enabled (somework_cqrs.outbox.enabled: true) but doctrine/dbal is not installed. Run "composer require doctrine/dbal" or set somework_cqrs.outbox.enabled to false.
```

The outbox also needs the `doctrine.dbal.<connection>_connection` service from
DoctrineBundle; without it Symfony reports that `somework_cqrs.outbox.storage`
depends on a non-existent service.

## Idempotency and outbox

### `IdempotencyStamp` does not deduplicate

**Cause.** One of the prerequisites is missing. In debug mode the container
compilation log (`var/cache/<env>/*Compiler.log`) contains one of:

```
Idempotency is enabled but needs symfony/messenger ^7.3 (DeduplicateStamp) and symfony/lock; IdempotencyStamp is ignored until both are installed.
```

```
Idempotency is enabled but Messenger's deduplicate middleware is not registered, so DeduplicateStamp is not enforced. Enable the lock component ("framework.lock").
```

If neither appears, check the lock store: the local `flock` and `semaphore`
stores release the lock immediately, so duplicates go through. Use a shared
store that keeps locks until their TTL expires, such as Redis or a database.
See [Production: idempotency](production.md#idempotency).

### Outbox table does not exist

**Symptom.**

```
LogicException: The outbox table "somework_cqrs_outbox" does not exist. Create it with "bin/console somework:cqrs:outbox:setup" or a Doctrine migration; it is never created inside an open transaction.
```

**Cause.** The first outbox message was stored inside your transaction before the
table existed. The bundle never creates the table inside a transaction: on
several databases a `CREATE TABLE` commits or aborts the open transaction.

**Fix.** Run `bin/console somework:cqrs:outbox:setup` during deployment, or add
the table with a Doctrine migration (and set `outbox.auto_setup: false`).

### The outbox relay reports problems

* `Another outbox relay is already running.` Another relay holds the lock; the
  command exits with `0`. Nothing to do unless no other relay is running, in
  which case check the configured lock store.
* `Message "..." (...) was not sent to any transport and was handled synchronously. Set a transport name or route the message to a transport.`
  The row has no transport name and no `framework.messenger.routing` entry
  matches it.
* `Failed to relay message "...": ...` The row was skipped and stays unpublished;
  the command exits with `1` and retries it on the next run.

## Health check failures

`somework:cqrs:health` exits with `2` when a result is `CRITICAL`:

* `Handler "..." cannot be instantiated: ...` A handler's constructor or one of
  its dependencies fails (often a missing environment variable).
* `Transport "..." cannot be created: ...` The transport DSN or options are
  invalid.
* `Checker "..." threw an exception: ...` A custom `HealthChecker` failed.

It exits with `1` for warnings, for example
`No handlers registered — this may indicate a configuration issue`. Before 0.5.0
the command reported every handler and transport as `CRITICAL`; upgrade if you
see that.

## Diagnostic commands

| Command | Use it to |
|---------|-----------|
| `somework:cqrs:list [--type=TYPE] [--details]` | See every handler per bus and, with `--details`, the resolved dispatch mode, transports, retry policy, serializer and metadata provider |
| `somework:cqrs:debug-transports` | See the `somework_cqrs.transports` defaults and per-message overrides |
| `somework:cqrs:health` | Instantiate all handlers and transports (exit code `0`, `1` or `2`) |
| `debug:messenger` | See Messenger's buses and the handlers registered on each |
| `debug:config somework_cqrs` | See the configuration after defaults are applied |

```bash
bin/console somework:cqrs:list --type=command --details
bin/console somework:cqrs:debug-transports
bin/console somework:cqrs:health
bin/console debug:config somework_cqrs
```
