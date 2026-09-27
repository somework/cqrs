# CQRS Bundle Example App

A minimal Symfony console application demonstrating [somework/cqrs-bundle](https://github.com/somework/cqrs) with a task-management domain on Doctrine ORM.

> This is an in-repo example for exploration. It installs the bundle from this repository
> through a Composer path repository (`../..`). For a real project, start with
> `composer create-project symfony/skeleton` and then `composer require somework/cqrs-bundle`.

## What is this?

A small Symfony app that uses all three bus types provided by the CQRS bundle, with its tasks in a
SQLite database:

- **Commands**: create and complete tasks (`CreateTask`, `CompleteTask`)
- **Queries**: retrieve tasks and what the event handlers did (`FindTaskById`, `ListTasks`, `ListActivity`)
- **Events**: the `Task` entity records `TaskCreated` and `TaskCompleted`. They are stored in the
  transactional outbox when the entity manager flushes, in the transaction of the change, and the
  relay hands them to their handlers afterwards ([domain events](../domain-events.md)).

No message broker or web server is needed: the relay sends the events to a `sync://` transport
(`events`), so their handlers run in the relay's process.

## Requirements

- PHP 8.2 or newer with `pdo_sqlite`, and Composer
- Symfony 7.2 or newer, including 8.x, Doctrine ORM 3 and DoctrineBundle (installed by Composer)

## Quick start

```bash
# Clone the repository (if you haven't already)
git clone https://github.com/somework/cqrs.git
cd cqrs/docs/example-app

# Install dependencies (the bundle is symlinked from the repository root)
composer install

# Create the database (var/data.db): the task tables and the outbox table
php bin/console doctrine:schema:create

# Run the demo: two CreateTask commands, one CompleteTask, then the ListTasks and FindTaskById queries
php bin/console app:demo

# Hand the events the demo stored in the outbox to their handlers, then see what they did
php bin/console somework:cqrs:outbox:relay
php bin/console app:activity
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

The Task entity recorded 3 event(s); they were stored in the outbox with the changes, and wait for the relay:
  php bin/console somework:cqrs:outbox:relay   # hands them to their handlers
  php bin/console app:activity                 # shows what the handlers did

Queries
-------

ListTasks:
 -------- ------------------------- --------
  ID       Title                     Status
 -------- ------------------------- --------
  task-1   Write the documentation   done
  task-2   Release version 0.6.0     open
 -------- ------------------------- --------

FindTaskById(task-2): "Release version 0.6.0" (open)

 [OK] Created 2 tasks, completed 1; 3 event(s) wait in the outbox.
```

Then the relay and the activity:

```text
$ php bin/console somework:cqrs:outbox:relay

 [OK] Relayed 3 message(s).

$ php bin/console app:activity
 * TaskCreated handled: "Write the documentation" (task-1)
 * TaskCreated handled: "Release version 0.6.0" (task-2)
 * TaskCompleted handled: task-1

 [OK] The relay handed 3 event(s) to their handlers.
```

To run the demo again, start from a fresh database: `rm -f var/data.db && php bin/console doctrine:schema:create`.

## Inspect the bundle setup

```bash
# Registered commands, queries and events with their handlers and buses
php bin/console somework:cqrs:list
php bin/console somework:cqrs:list --type=command --details

# Instantiates every CQRS handler and Messenger transport, and reports the outbox backlog;
# exit code 0 means healthy
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
`--prefer-*` option), creates a fresh database, runs `somework:cqrs:outbox:setup`, `app:demo`, the
relay (which must relay the 3 events), `app:activity` (which must list what their handlers did), a
second relay (with nothing left to relay), `somework:cqrs:list` and `somework:cqrs:health`, and stops
at the first failing step. Extra arguments are passed to `composer install`:

```bash
bin/smoke-test.sh
bin/smoke-test.sh --prefer-source
```

## What to look at

| File | What it shows |
|------|---------------|
| `src/Command/DemoCommand.php` | Console command using `CommandBusInterface::dispatch()` / `dispatchSync()` and `QueryBusInterface::ask()`, and the outbox backlog (`OutboxMonitoring`) |
| `src/Task/Task.php` | Entity recording its events with `RecordsEventsTrait::recordThat()` |
| `src/Task/Command/CreateTask.php` | Immutable command DTO with `readonly` properties |
| `src/Task/Command/CreateTaskHandler.php` | Handler registered with `#[AsCommandHandler]`: it persists the entity and dispatches nothing |
| `src/Task/Event/TaskCreated.php` | Event recorded by the entity, stored in the outbox with the change |
| `src/Task/Event/TaskCreatedHandler.php` | Event handler run by the relay, writing the activity read model |
| `src/Task/Query/ListTasks.php` | Zero-property query (valid pattern for "list all" queries) |
| `src/Task/Query/FindTaskByIdHandler.php` | Query handler returning the result to `QueryBus::ask()` |
| `config/services.yaml` | Autowired and autoconfigured `App\` services; no manual handler wiring |
| `config/packages/doctrine.yaml` | SQLite connection and the attribute mapping of `src/Task` |
| `config/packages/messenger.yaml` | One Messenger bus per message type, `doctrine_transaction` on the command and event buses, and the `events` transport (`sync://`) the relay sends the events to |
| `config/packages/somework_cqrs.yaml` | Bundle configuration: the outbox, `doctrine_events`, the transport of the relayed events, and commented retry examples |

## Key concepts demonstrated

**Auto-discovery**: handlers are regular services loaded from `src/` with `autoconfigure: true`.
The bundle registers every class carrying `#[AsCommandHandler]`, `#[AsQueryHandler]` or
`#[AsEventHandler]`, or implementing the `CommandHandler`, `QueryHandler` or `EventHandler` marker
interface. No manual service wiring is needed.

**Handler contracts**: each handler has a typed `__invoke()` (for example
`__invoke(CreateTask $command): mixed`). The attribute names the handled message. The marker
interfaces declare no methods, so the handlers here implement them only as documentation; a handler
registered through a marker interface alone gets its message from the type of the first
`__invoke()` parameter.

**Typed buses**: commands, queries and events each have their own bus (`command.bus`, `query.bus`
and `event.bus` in `messenger.yaml`, mapped under `somework_cqrs.buses`). Commands and events support
sync and async dispatch; queries are always synchronous and must have exactly one handler.

**Domain events recorded by entities**: `Task::create()` and `Task::complete()` record events; no
handler dispatches them. The command bus runs each handler in a transaction (`doctrine_transaction`)
and flushes the entity manager when it returns: with `somework_cqrs.doctrine_events` enabled, that
flush stores the recorded events in the outbox in the same transaction, so they exist if and only if
the change committed. A flush outside a transaction is refused. See [Domain events](../domain-events.md).

**Transactional outbox and relay**: `somework:cqrs:outbox:relay` sends the stored events to the
transport of `transports.event_async` (`events`, a `sync://` transport here), whose handlers run right away in the relay's
process, each in its own transaction. The events continue the flow of the command that caused them:
same correlation id, the command as cause. See [Transactional outbox](../outbox.md).

**Stamp pipeline**: every dispatch goes through the bundle's stamp deciders. The correlation id the
demo prints comes from the `MessageMetadataStamp` added by the default metadata provider. Retry
policies, transports, serializers and metadata providers can be configured per message; see the
commented examples in `somework_cqrs.yaml` and `php bin/console somework:cqrs:list --details`.

## Trying other setups

- **Relay automatically in development**: uncomment `relay_on_terminate: true` under `outbox` in
  `somework_cqrs.yaml`. The relay then runs right after each command that stored events, so
  `app:activity` shows them without running the relay yourself. Leave it off in production and run
  the relay on a schedule or with `--watch`.
- **Handle the events in a worker**: point the `events` transport of `messenger.yaml` at a
  persistent transport (for example `'doctrine://default'` with `symfony/doctrine-messenger`), then
  run `php bin/console messenger:consume events` after the relay. The relay only sends the events;
  the worker handles them, with Messenger's retries. See the [Production guide](../production.md)
  for worker setup.

## Learn more

- [Getting Started Guide](../getting-started.md)
- [Domain events](../domain-events.md)
- [Usage Guide](../usage.md)
- [Configuration Reference](../reference.md)
- [Bundle README](https://github.com/somework/cqrs#readme)
