# Troubleshooting

This guide covers common issues when integrating the CQRS bundle and how to
resolve them.

## 1. Handler not found

### Symptom

One of the following errors at runtime or compile time:

**Runtime (dispatch):**

```
NoHandlerException: No handler found for "App\Application\Command\CreateTask" dispatched on the messenger.bus.commands bus.
```

**Compile time (container build):**

```
LogicException: CQRS handler validation failed:
Command App\Application\Command\CreateTask has no handler registered.
```

### Cause

The handler is not registered for the message. Common reasons:

1. **Missing attribute or interface.** The handler class does not have the
   `#[AsCommandHandler]` attribute or does not implement `CommandHandler`.
2. **Type-hint mismatch.** The handler's `__invoke()` parameter type does not
   match the message class specified in the attribute.
3. **Excluded from autoconfiguration.** The handler class lives outside the
   directory scanned by `config/services.yaml` (e.g., a separate package not
   included in the `resource` glob).
4. **Wrong bus name.** The attribute specifies a bus that does not match the
   bundle configuration: `#[AsCommandHandler(bus: 'wrong.bus')]`.

### Solution

1. Verify the handler has both the attribute and the marker interface:

   ```php
   use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
   use SomeWork\CqrsBundle\Contract\CommandHandler;

   #[AsCommandHandler(command: CreateTask::class)]
   final class CreateTaskHandler implements CommandHandler
   {
       public function __invoke(CreateTask $command): mixed { /* ... */ }
   }
   ```

2. Confirm the `__invoke()` parameter type-hint matches the message class
   referenced in the attribute.

3. Check that the handler's namespace is covered by your service configuration:

   ```yaml
   # config/services.yaml
   services:
       App\:
           resource: '../src/'
           autoconfigure: true
           autowire: true
   ```

4. Run the diagnostic command to confirm registration:

   ```bash
   bin/console somework:cqrs:list
   ```

   If the handler does not appear, the issue is in service discovery or
   attribute configuration.

---

## 2. Wrong bus routing

### Symptom

A command or query is dispatched but the handler never executes, or the handler
runs on an unexpected bus (visible in profiler or logs).

### Cause

1. **Mismatched bus ID.** `somework_cqrs.buses.command` points to a Messenger
   bus ID that differs from the one the handler is tagged for.
2. **Handler on wrong bus.** The handler attribute specifies bus A, but dispatch
   goes through bus B.
3. **Multiple bus configuration.** When using multiple Messenger buses, the bus
   names in handler attributes must match the bus IDs in
   `somework_cqrs.buses.*`.

### Solution

1. Run `somework:cqrs:list` with details to see which bus each handler is on:

   ```bash
   bin/console somework:cqrs:list --details
   ```

2. Cross-reference the output with your bundle configuration:

   ```yaml
   somework_cqrs:
       buses:
           command: messenger.bus.commands      # must match handler bus
           query: messenger.bus.queries
           event: messenger.bus.events
   ```

3. Verify Messenger bus definitions match:

   ```bash
   bin/console debug:messenger
   ```

4. If a handler specifies an explicit bus in its attribute, confirm it matches
   one of the configured bus IDs:

   ```php
   #[AsCommandHandler(command: CreateTask::class, bus: 'messenger.bus.commands')]
   ```

---

## 3. Async message dispatching synchronously

### Symptom

A message configured for asynchronous dispatch runs in the same HTTP request
(blocking). No message appears in the transport queue.

### Cause

1. **Dispatch mode not configured.** The `somework_cqrs.dispatch_modes` config
   does not list the message class in its `map`, so the default (sync) applies.
2. **Missing async bus.** `somework_cqrs.buses.command_async` is not set, so the
   bundle has no async bus to dispatch to.
3. **Explicit sync override.** The caller passes `DispatchMode::SYNC` as the
   second argument to `dispatch()`, overriding the config default.
4. **Missing Messenger routing.** Even with async dispatch mode, Messenger needs
   a `framework.messenger.routing` entry to route the message to a transport.

### Solution

1. Configure async dispatch for the message:

   ```yaml
   somework_cqrs:
       dispatch_modes:
           command:
               default: sync
               map:
                   App\Application\Command\GenerateReport: async
   ```

2. Ensure the async bus is configured:

   ```yaml
   somework_cqrs:
       buses:
           command_async: messenger.bus.commands_async
   ```

3. Add Messenger transport routing:

   ```yaml
   framework:
       messenger:
           routing:
               'App\Application\Command\GenerateReport': async
   ```

4. Verify transport mapping with the debug command:

   ```bash
   bin/console somework:cqrs:debug-transports
   ```

5. Check that caller code does not pass an explicit `DispatchMode::SYNC`:

   ```php
   // Wrong -- forces sync even if config says async
   $commandBus->dispatch($command, DispatchMode::SYNC);

   // Correct -- respects config
   $commandBus->dispatch($command);
   ```

---

## 4. Transport misconfiguration

### Symptom

One of the following errors:

**Runtime:**

```
AsyncBusNotConfiguredException: Asynchronous command bus is not configured. Cannot dispatch "App\Application\Command\CreateTask" in async mode.
```

**Compile time:**

```
InvalidConfigurationException: Transport name "nonexistent_transport" is not defined in framework.messenger.transports.
```

Or messages are silently sent to the wrong transport.

### Cause

1. **Async bus not set.** `somework_cqrs.buses.command_async` is null but
   `dispatch_modes.command.default` is `async`, so the bundle cannot find an
   async bus to dispatch to.
2. **Transport name mismatch.** A transport name in
   `somework_cqrs.transports.*.map` does not match any entry in
   `framework.messenger.transports`.
3. **Stamp class unavailable.** Using `SendMessageToTransportsStamp` on Symfony
   versions before 6.3 where the stamp class does not exist.

### Solution

1. Set the async bus IDs for every type that uses async dispatch:

   ```yaml
   somework_cqrs:
       buses:
           command_async: messenger.bus.commands_async
           event_async: messenger.bus.events_async
   ```

2. Run the transport debug command to audit all transport mappings:

   ```bash
   bin/console somework:cqrs:debug-transports
   ```

3. Cross-reference transport names in bundle config with Messenger transports:

   ```yaml
   # Bundle config
   somework_cqrs:
       transports:
           command_async:
               default: ['async_commands']

   # Must match a Messenger transport
   framework:
       messenger:
           transports:
               async_commands:
                   dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
   ```

4. Check your Symfony version if using `SendMessageToTransportsStamp`. The stamp
   requires Symfony 6.3 or later. For older versions, use the default
   `transport_names` stamp type.

---

## Diagnostic commands

The bundle ships three console commands for inspecting your CQRS configuration:

| Command | Purpose | When to use |
|---------|---------|-------------|
| `somework:cqrs:list` | Lists all registered commands, queries, and events with handler metadata | Verify handlers are discovered and assigned to the correct bus |
| `somework:cqrs:debug-transports` | Inspects Messenger transport routing for CQRS messages | Diagnose transport name mismatches and async routing issues |
| `somework:cqrs:generate` | Scaffolds a message + handler skeleton | Bootstrap new messages with correct attribute and interface boilerplate |

### Usage examples

```bash
# Show all handlers
bin/console somework:cqrs:list

# Filter by message type
bin/console somework:cqrs:list --type=command

# Show detailed handler info including bus assignments
bin/console somework:cqrs:list --details

# Audit transport routing
bin/console somework:cqrs:debug-transports

# Generate a new command with handler
bin/console somework:cqrs:generate command 'App\Application\Command\ShipOrder'
```
