# CQRS Bundle Example App

A minimal Symfony console application demonstrating [somework/cqrs-bundle](https://github.com/somework/cqrs) with a task-management domain.

> This is an in-repo example for exploration. It installs the bundle from this repository
> through a Composer path repository (`../..`). For a real project, start with
> `composer create-project symfony/skeleton` and then `composer require somework/cqrs-bundle`.

## What is this?

A small Symfony app that uses all three bus types provided by the CQRS bundle:

- **Commands**: create and complete tasks (`CreateTask`, `CompleteTask`)
- **Queries**: retrieve tasks (`FindTaskById`, `ListTasks`)
- **Events**: react to side effects (`TaskCreated`, dispatched by the `CreateTask` handler)

Everything runs synchronously in one process and is stored in memory, so no database,
message broker or web server is needed.

## Requirements

- PHP 8.2 or newer and Composer
- Symfony 7.2 or newer, including 8.x (installed by Composer)

## Quick start

```bash
# Clone the repository (if you haven't already)
git clone https://github.com/somework/cqrs.git
cd cqrs/docs/example-app

# Install dependencies (the bundle is symlinked from the repository root)
composer install

# Run the demo: two CreateTask commands, one CompleteTask, then the ListTasks and FindTaskById queries
php bin/console app:demo
```

The demo prints something like this (the correlation id is random):

```text
Commands
--------

CreateTask(task-1) dispatched, correlation id: 6869d2fc3588dc765ab06c9179494fc7
CreateTask(task-2) handled
CompleteTask(task-1) handled

Events
------

 * TaskCreated handled: "Write the documentation" (task-1)
 * TaskCreated handled: "Release version 0.5.0" (task-2)

Queries
-------

ListTasks:
 -------- ------------------------- --------
  ID       Title                     Status
 -------- ------------------------- --------
  task-1   Write the documentation   done
  task-2   Release version 0.5.0     open
 -------- ------------------------- --------

FindTaskById(task-2): "Release version 0.5.0" (open)

 [OK] Created 2 tasks, completed 1, handled 2 event(s).
```

## Inspect the bundle setup

```bash
# Registered commands, queries and events with their handlers and buses
php bin/console somework:cqrs:list
php bin/console somework:cqrs:list --type=command --details

# Instantiates every CQRS handler and Messenger transport; exit code 0 means healthy
php bin/console somework:cqrs:health

# Transport routing of CQRS messages
php bin/console somework:cqrs:debug-transports

# Symfony's own views of the same wiring
php bin/console debug:messenger
php bin/console debug:container 'SomeWork\CqrsBundle\Contract\CommandBusInterface'
php bin/console lint:container
```

## Generate a message and its handler

The type and the class name are arguments:

```bash
php bin/console somework:cqrs:generate command 'App\Task\Command\ArchiveTask'
```

This creates `src/Task/Command/ArchiveTask.php` and `src/Task/Command/ArchiveTaskHandler.php`
(paths follow the `App\` → `src/` PSR-4 mapping in `composer.json`). The new handler shows up in
`php bin/console somework:cqrs:list` right away. Delete both files to get back to the original app.

## Smoke test

`bin/smoke-test.sh` runs `composer install` (with `--prefer-dist` unless you pass another
`--prefer-*` option), `app:demo`, `somework:cqrs:list` and `somework:cqrs:health`, and stops at the
first failing step. Extra arguments are passed to `composer install`:

```bash
bin/smoke-test.sh
bin/smoke-test.sh --prefer-source
```

## What to look at

| File | What it shows |
|------|---------------|
| `src/Command/DemoCommand.php` | Console command using `CommandBusInterface::dispatch()` / `dispatchSync()` and `QueryBusInterface::ask()` |
| `src/Task/Command/CreateTask.php` | Immutable command DTO with `readonly` properties |
| `src/Task/Command/CreateTaskHandler.php` | Handler registered with `#[AsCommandHandler]`, dispatching `TaskCreated` through `EventBusInterface` |
| `src/Task/Query/ListTasks.php` | Zero-property query (valid pattern for "list all" queries) |
| `src/Task/Query/FindTaskByIdHandler.php` | Query handler returning the result to `QueryBus::ask()` |
| `src/Task/Event/TaskCreated.php` | Event dispatched as a side effect of command handling |
| `src/Task/Event/TaskCreatedHandler.php` | Event handler recording what happened in `TaskActivityLog` |
| `src/Task/InMemoryTaskStore.php` | Simple storage service injected into handlers |
| `config/services.yaml` | Autowired and autoconfigured `App\` services; no manual handler wiring |
| `config/packages/messenger.yaml` | One Messenger bus per message type; async buses and transport commented out |
| `config/packages/somework_cqrs.yaml` | Bundle configuration, with commented async, transport and retry examples |

## Key concepts demonstrated

**Auto-discovery**: handlers are regular services loaded from `src/` with `autoconfigure: true`.
The bundle registers every class carrying `#[AsCommandHandler]`, `#[AsQueryHandler]` or
`#[AsEventHandler]`, or implementing the `CommandHandler`, `QueryHandler` or `EventHandler` marker
interface. No manual service wiring is needed.

**Handler contracts**: each handler has a typed `__invoke()` (for example
`__invoke(CreateTask $command): mixed`). The attribute names the handled message; the marker
interfaces declare no methods, so implementing one is optional. Without the attribute, the message
is taken from the type of the first `__invoke()` parameter.

**Typed buses**: commands, queries and events each have their own bus (`command.bus`, `query.bus`
and `event.bus` in `messenger.yaml`, mapped under `somework_cqrs.buses`). Commands and events support
sync and async dispatch; queries are always synchronous and must have exactly one handler.

**Stamp pipeline**: every dispatch goes through the bundle's stamp deciders. The correlation id the
demo prints comes from the `MessageMetadataStamp` added by the default metadata provider. Retry
policies, transports, serializers and metadata providers can be configured per message; see the
commented examples in `somework_cqrs.yaml` and `php bin/console somework:cqrs:list --details`.

## Trying asynchronous events

1. In `config/packages/messenger.yaml`, uncomment the `command.async_bus` and `event.async_bus`
   buses and the `transports` block (`async: 'in-memory://'`).
2. In `config/packages/somework_cqrs.yaml`, uncomment `command_async` and `event_async` under
   `buses`, the `map` under `dispatch_modes.event` and the `transports` block.
3. Run `php bin/console app:demo` again. `TaskCreated` is now sent to the `async` transport instead
   of being handled in the process, so the demo reports 0 handled events, and
   `php bin/console somework:cqrs:list --type=event --details` shows the `async` dispatch mode and transport.

The `in-memory://` transport keeps messages inside the PHP process, which is enough to see the
routing. To process messages with a worker (`php bin/console messenger:consume async`), use a
persistent transport DSN; see the [Usage Guide](../usage.md).

## Learn more

- [Getting Started Guide](../getting-started.md)
- [Usage Guide](../usage.md)
- [Configuration Reference](../reference.md)
- [Bundle README](https://github.com/somework/cqrs#readme)
