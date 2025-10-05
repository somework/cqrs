# SomeWork CQRS Bundle

A set of CQRS helpers for Symfony Messenger. The bundle wires command, query,
and event buses to Messenger, discovers handlers automatically, and ships with
tooling that keeps your catalogue maintainable.

## Features

* PHP attributes (`#[AsCommandHandler]`, `#[AsQueryHandler]`, `#[AsEventHandler]`)
  and marker interfaces that auto-tag handlers for Messenger.
* Console tooling to list registered handlers and scaffold new messages.
* Configuration hooks for naming strategies, retry policies, serializer stamps,
  and metadata providers.
* Plays nicely with multiple Messenger buses (sync and async).

## Installation

### Requirements

* PHP 8.2 or newer.
* Symfony FrameworkBundle 6.4 or 7.x.
* Symfony Messenger 6.4 or 7.x.

Install the bundle via Composer:

```bash
composer require somework/cqrs-bundle
```

Then enable it in `config/bundles.php`:

```php
return [
    // ...
    SomeWork\CqrsBundle\SomeWorkCqrsBundle::class => ['all' => true],
];
```

Run the bundled console tooling to verify the bundle is registered:

```bash
bin/console somework:cqrs:list
```

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

## Resources

* [Changelog](CHANGELOG.md)

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
        command:
            default: SomeWork\CqrsBundle\Support\NullRetryPolicy
            map:
                App\Application\Command\ShipOrder: app.command.retry_policy
                App\Domain\Contract\RequiresImmediateRetry: app.command.retry_policy_for_interface
    serialization:
        default: SomeWork\CqrsBundle\Support\NullMessageSerializer
        command:
            default: null
            map:
                App\Application\Command\ShipOrder: app.command.serializer
        query:
            default: app.query_serializer
            map: {}
        event:
            default: SomeWork\CqrsBundle\Support\NullMessageSerializer
            map:
                App\Domain\Event\OrderShipped: app.event.serializer
    metadata:
        default: SomeWork\CqrsBundle\Support\RandomCorrelationMetadataProvider
        command:
            default: null
            map:
                App\Application\Command\ShipOrder: app.command.metadata_provider
        query:
            default: null
            map: {}
        event:
            default: null
            map:
                App\Domain\Event\OrderShipped: app.event.metadata_provider
    dispatch_modes:
        command:
            default: sync
            map:
                App\Application\Command\ShipOrder: async
        event:
            default: sync
            map:
                App\Domain\Event\OrderShipped: async
    async:
        dispatch_after_current_bus:
            command:
                default: true
                map:
                    App\Application\Command\ShipOrder: false
            event:
                default: true
                map: {}
```

Use the `map` section inside each `retry_policies` entry to override the
default policy for specific messages while keeping a shared fallback for the
rest of the type. Keys may reference concrete messages, parent classes, or
interfaces so you can coordinate retry behaviour across a group of messages.

`serialization` follows the same shape. Configure a `default` service applied to
every message type, override each type via its `default` entry, and list
message-specific serializer services inside `map`. The buses check for
message-specific serializers first, then fall back to the type default and
finally to the global default.

`metadata` controls which `MessageMetadataStamp` gets appended to dispatched
messages. The bundle defaults to generating random correlation identifiers via
`RandomCorrelationMetadataProvider`. Override the per-type `default` or
configure `map` entries when you need deterministic IDs for specific messages.

`dispatch_modes` lets you pick whether commands and events run on their
synchronous or asynchronous Messenger buses when callers omit the `DispatchMode`
argument. Use `async.dispatch_after_current_bus` to control Messenger's
`DispatchAfterCurrentBusStamp`. Keep the defaults enabled so async messages wait
for the current bus to finish before being dispatched, or flip individual
messages to `false` when they should be sent immediately.

Need additional stamps? Implement `SomeWork\CqrsBundle\Support\StampDecider`, tag
the service with `somework_cqrs.dispatch_stamp_decider`, and the bundle will run
it alongside the built-in `DispatchAfterCurrentBusStamp` logic.

### How message overrides are resolved

Whenever a configuration section exposes a `map` of message-specific services
(`retry_policies`, `serialization`, `dispatch_modes`, or
`async.dispatch_after_current_bus`), the bundle resolves the entry using a shared
matching strategy. The lookup happens in three steps:

1. Check for an **exact class match**.
2. Walk up the **parent class hierarchy**, returning the first configured
   ancestor.
3. Evaluate **interfaces** implemented by the message, followed by any parent
   interfaces, and pick the first configured entry.

This ordering keeps overrides predictable – concrete classes always win, then
inheritance, then shared contracts. If nothing matches the resolver falls back
to the type-specific `default` and, when available, the global default service.

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
