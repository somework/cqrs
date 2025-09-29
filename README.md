# SomeWork CQRS Bundle

A set of CQRS helpers for Symfony Messenger. The bundle wires command, query,
and event buses to Messenger, discovers handlers automatically, and ships with
tooling that keeps your catalogue maintainable.

## Features

* PHP attributes (`#[AsCommandHandler]`, `#[AsQueryHandler]`, `#[AsEventHandler]`)
  and marker interfaces that auto-tag handlers for Messenger.
* Console tooling to list registered handlers and scaffold new messages.
* Configuration hooks for naming strategies, retry policies, and serializer
  stamps.
* Plays nicely with multiple Messenger buses (sync and async).

## Quick start

```php
<?php

namespace App\Application\Command;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\CommandHandler;

final class ShipOrder implements Command
{
    public function __construct(public readonly string $orderId) {}
}

#[AsCommandHandler(command: ShipOrder::class)]
final class ShipOrderHandler implements CommandHandler
{
    public function __invoke(ShipOrder $command): mixed
    {
        // Dispatch domain logic here
        return null;
    }
}
```

Inject `SomeWork\CqrsBundle\Bus\CommandBus` and call
`$commandBus->dispatch(new ShipOrder($id));` to execute your handler.

## Console commands

* `bin/console somework:cqrs:list` – renders a table of commands, queries, and
  events detected in the container. Filter by message type via
  `--type=command|query|event`.
* `bin/console somework:cqrs:generate command App\Application\Command\ShipOrder`
  – generates a message and handler skeleton under your project `src/`
  directory. Use `--dir=` to override the base directory and `--force` to
  overwrite existing files.

### Handler registry and diagnostics

The bundle exposes a `SomeWork\CqrsBundle\Registry\HandlerRegistry` service that
holds the metadata discovered at compile time. You can inject it to build custom
dashboards, health checks, or documentation. The bundled `somework:cqrs:list`
command uses the registry to produce a concise overview of your catalogue:

```
$ bin/console somework:cqrs:list
+---------+--------------------------------------------+-----------------------------------------------+----------------------------------------------+--------------------------+
| Type    | Message                                    | Handler                                       | Service Id                                   | Bus                      |
+---------+--------------------------------------------+-----------------------------------------------+----------------------------------------------+--------------------------+
| Command | App\Application\Command\ShipOrder          | App\Application\Command\ShipOrderHandler      | app.command.ship_order_handler               | messenger.bus.commands   |
| Query   | App\ReadModel\Query\FindOrder              | App\ReadModel\Query\FindOrderHandler          | app.read_model.find_order_handler            | default                  |
+---------+--------------------------------------------+-----------------------------------------------+----------------------------------------------+--------------------------+
```

### Message scaffolding options

The generator accepts a handful of options so you can tailor the output to your
project layout:

* `--handler=App\\Application\\Command\\ShipOrderHandler` – override the
  handler class name instead of using the `<Message>Handler` default.
* `--dir=app/src` – change the base directory used to materialise the class
  files. The argument is relative to the project root returned by the kernel.
* `--force` – replace existing files instead of halting with an error.

## Configuration

All options live under the `somework_cqrs` key. They allow you to point each
CQRS facade at specific Messenger buses, override naming strategies, and provide
retry/serialization policies that append Messenger stamps.

```yaml
somework_cqrs:
    default_bus: messenger.default_bus
    buses:
        command: messenger.bus.commands
        command_async: messenger.bus.commands_async
        query: messenger.bus.queries
        event: messenger.bus.events
        event_async: messenger.bus.events_async
    naming:
        default: SomeWork\CqrsBundle\Support\ClassNameMessageNamingStrategy
    retry_policies:
        command: SomeWork\CqrsBundle\Support\NullRetryPolicy
    serialization:
        command: SomeWork\CqrsBundle\Support\NullMessageSerializer
```

See [`docs/reference.md`](docs/reference.md) for a complete description of every
option and [`docs/usage.md`](docs/usage.md) for more examples.

## Messenger configuration

The bundle relies on standard Messenger buses. Configure them according to your
environment (sync, async, transports) and wire the CQRS facades to the desired
bus ids.

### Shared defaults (`config/packages/messenger.yaml`)

```yaml
framework:
    messenger:
        default_bus: messenger.bus.commands
        buses:
            messenger.bus.commands: ~
            messenger.bus.commands_async:
                default_middleware:
                    enabled: true
            messenger.bus.queries: ~
            messenger.bus.events: ~
            messenger.bus.events_async:
                default_middleware:
                    enabled: true
```

### Production transport setup (`config/packages/prod/messenger.yaml`)

Configure real transports and routing so asynchronous commands and events leave
the HTTP process.

```yaml
framework:
    messenger:
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    auto_setup: false
        routing:
            'App\\Application\\Command\\GenerateReportCommand': async
            'App\\Domain\\Event\\TaskCreated': async
```

Run the worker with `bin/console messenger:consume async` to process queued
messages.

### Development overrides (`config/packages/dev/messenger.yaml`)

Point the async transport at a developer-friendly backend and allow Messenger to
create it on the fly:

```yaml
framework:
    messenger:
        transports:
            async:
                dsn: '%env(resolve:MESSENGER_TRANSPORT_DSN)%'
                options:
                    auto_setup: true
```

### Test overrides (`config/packages/test/messenger.yaml`)

Use an in-memory transport so functional tests can assert on dispatched messages
without spawning workers.

```yaml
framework:
    messenger:
        transports:
            async: 'in-memory://'
        routing:
            'App\\Application\\Command\\GenerateReportCommand': async
            'App\\Domain\\Event\\TaskCreated': async
```

With these settings the command, event, and query bus facades provided by the
bundle transparently adapt to each environment.
