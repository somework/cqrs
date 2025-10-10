# Usage guide

This bundle layers a CQRS-friendly API on top of Symfony Messenger. It provides
attribute-based autoconfiguration, optional interfaces, and tooling to keep your
handler catalogue discoverable.

## Registering handlers with attributes

Annotate handlers with the provided attributes to automatically add the correct
`messenger.message_handler` tags. The bundle will infer the bus from your
configuration when the `bus` argument is omitted.

```php
<?php

namespace App\Application\Command;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\CommandHandler;

final class ApproveInvoice implements Command
{
    public function __construct(
        public readonly string $invoiceId,
    ) {
    }
}

#[AsCommandHandler(command: ApproveInvoice::class)]
final class ApproveInvoiceHandler implements CommandHandler
{
    public function __invoke(ApproveInvoice $command): mixed
    {
        // Handle the command…
        return null;
    }
}
```

The same pattern exists for queries (`#[AsQueryHandler]`) and events
(`#[AsEventHandler]`).

### Fire-and-forget events

Dispatching an event without any listeners used to trigger Symfony
Messenger's `NoHandlerForMessageException`. The bundle now ships an internal
middleware that is automatically added to every configured event bus
(`buses.event`, `buses.event_async`, or the `default_bus` fallback). The
middleware catches the exception and returns the original envelope when the
message implements `SomeWork\CqrsBundle\Contract\Event`, allowing you to
publish integration or domain events before any projections subscribe to
them. Command and query buses keep Messenger's default behaviour so unexpected
gaps in their handler catalogues still surface during development.

## Interface autoconfiguration

If you prefer interfaces over attributes the bundle can still discover your
handlers. Implement one of the marker interfaces and type-hint the message on
`__invoke()`. The compiler pass inspects the argument type to figure out which
message the handler is responsible for.

When wiring services manually with the `messenger.message_handler` tag you can
set the `method` attribute to point at a handler method other than `__invoke()`.
The compiler pass will reflect that method to determine the message type when
`handles` is not provided.

The `handles` attribute accepts either a single message class string, an array
of class strings, or an associative array where the keys are the message
classes. Associative definitions let you pair classes with method names or
options understood by Messenger (for example `['method' => 'handle']` or
`['from_transport' => 'async']`). The bundle now records the message classes
from either format so downstream metadata, console tooling, and runtime
dispatching stay in sync with Messenger's supported tag shapes.

```php
<?php

namespace App\ReadModel;

use App\Domain\Event\InvoicePaid;
use SomeWork\CqrsBundle\Contract\EventHandler;

final class InvoicePaidProjector implements EventHandler
{
    public function __invoke(InvoicePaid $event): void
    {
        // Persist read model changes here.
    }
}
```

## Console tooling

Three commands ship with the bundle once it is registered in your kernel:

* `somework:cqrs:list` renders a table of discovered messages and their
  handlers. Use `--type=command` (or `query` / `event`) to focus the output.
* `somework:cqrs:generate` scaffolds a message class and handler skeleton. Run
  `bin/console somework:cqrs:generate command App\Application\Command\ShipOrder`
  to produce `ShipOrder` and `ShipOrderHandler` inside your project `src/`
  directory. Pass `--dir=app/src` and `--force` to customise the target or
  overwrite existing files.
* `somework:cqrs:debug-transports` prints the default Messenger transports for
  each CQRS bus alongside every explicit override so you can audit routing
  across your application.

Pass `--details` to `somework:cqrs:list` to inspect the resolved dispatch
configuration for each handler:

```
$ bin/console somework:cqrs:list --details
+---------+---------------+-------------------------------------------+--------------------------+---------------------------+---------------+-------------+-------------------------------+------------------------------------------+-----------------------------------------------+
| Type    | Message       | Handler                                   | Service Id               | Bus                       | Dispatch Mode | Async Defers | Retry Policy                  | Serializer                               | Metadata Provider                           |
+---------+---------------+-------------------------------------------+--------------------------+---------------------------+---------------+-------------+-------------------------------+------------------------------------------+-----------------------------------------------+
| Command | Ship order    | App\Application\Command\ShipOrderHandler  | app.command.ship_handler | messenger.bus.commands    | async         | yes         | App\Infra\Retry\ShipOrders    | App\Infra\Serializer\ShipOrderSerializer | App\Support\Metadata\CorrelationMetadata    |
| Query   | Find order    | App\ReadModel\Query\FindOrderHandler      | app.read_model.finder    | default                   | sync          | n/a         | SomeWork\CqrsBundle\Support\NullRetryPolicy | SomeWork\CqrsBundle\Support\NullMessageSerializer | SomeWork\CqrsBundle\Support\RandomCorrelationMetadataProvider |
| Event   | Order shipped | App\Domain\Event\OrderShippedListener    | app.event.shipped        | messenger.bus.events_async | async         | no          | SomeWork\CqrsBundle\Support\NullRetryPolicy | SomeWork\CqrsBundle\Support\NullMessageSerializer | SomeWork\CqrsBundle\Support\RandomCorrelationMetadataProvider |
+---------+---------------+-------------------------------------------+--------------------------+---------------------------+---------------+-------------+-------------------------------+------------------------------------------+-----------------------------------------------+
```

Each extra column corresponds to the configuration the bundle resolved for that
handler:

* **Dispatch Mode** – Whether the handler will receive the message on the
  synchronous or asynchronous bus when callers omit an explicit
  `DispatchMode`.
* **Async Defers** – Shows whether `DispatchAfterCurrentBusStamp` is appended
  when the message is sent to an async transport. `n/a` appears for queries,
  which are always synchronous.
* **Retry Policy**, **Serializer**, and **Metadata Provider** – The services the
  container selected for the message, allowing you to verify overrides at a
  glance.

Example output from `somework:cqrs:list`:

```
$ bin/console somework:cqrs:list --type=command --type=query
+---------+--------------------------------------------+-----------------------------------------------+----------------------------------------------+--------------------------+
| Type    | Message                                    | Handler                                       | Service Id                                   | Bus                      |
+---------+--------------------------------------------+-----------------------------------------------+----------------------------------------------+--------------------------+
| Command | App\Application\Command\ShipOrder          | App\Application\Command\ShipOrderHandler      | app.command.ship_order_handler               | messenger.bus.commands   |
| Query   | App\ReadModel\Query\FindOrder              | App\ReadModel\Query\FindOrderHandler          | app.read_model.find_order_handler            | default                  |
+---------+--------------------------------------------+-----------------------------------------------+----------------------------------------------+--------------------------+
```

The command respects naming strategies registered for each message type so the
display names stay meaningful even when your classes follow domain-specific
conventions.

For the generator you can pass the following options to tailor the output:

* `--handler` – override the handler class name. The command still generates the
  attribute and interface wiring for you.
* `--dir` – write the files to a custom base directory. Useful when your source
  tree lives outside the default `src/` folder.
* `--force` – overwrite existing files instead of aborting. Handy when you want
  to regenerate boilerplate after renaming namespaces.

Inject `SomeWork\CqrsBundle\Registry\HandlerRegistry` if you need direct access
to the metadata powering the CLI. It offers `all()`, `byType()`, and
`getDisplayName()` helpers that you can reuse in dashboards or smoke tests.

These commands respect the naming strategy configured for the bundle when
presenting handler information.

For transport routing specifically, rely on `bin/console
somework:cqrs:debug-transports` as the canonical source of truth. The command
reflects the compiled container configuration, so you can confirm which
Messenger transports will receive your CQRS messages before rolling out
infrastructure changes.

See the [configuration reference](reference.md) for the exhaustive list of
options you can tune.

## Messenger integration

The bundle does not replace Messenger configuration. Configure your buses and
transports as usual and wire the CQRS buses to the appropriate Messenger buses.
See the reference documentation for the list of configurable options.

Handlers that implement `SomeWork\CqrsBundle\Contract\EnvelopeAware` (or use the
bundled `EnvelopeAwareTrait`) automatically receive the current Messenger
`Envelope` before execution. The bundle decorates the handlers locator for each
configured CQRS bus so that `setEnvelope()` is invoked for both synchronous and
asynchronous handlers, allowing you to access stamps and metadata via
`$this->getEnvelope()`.

## Choosing synchronous or asynchronous dispatch

Every bus accepts an optional `DispatchMode` argument. When it is omitted the
bundle falls back to the defaults defined in `somework_cqrs.dispatch_modes`. The
following configuration keeps most commands synchronous while routing
`ShipOrder` asynchronously:

```yaml
# config/packages/somework_cqrs.yaml
somework_cqrs:
    dispatch_modes:
        command:
            default: sync
            map:
                App\Application\Command\ShipOrder: async
```

At runtime you can still make an explicit choice:

```php
use SomeWork\CqrsBundle\Contract\DispatchMode;

$commandBus->dispatch($command);                 // Uses the resolved default
$commandBus->dispatch($command, DispatchMode::ASYNC);
$commandBus->dispatchAsync($command);            // Shortcut for DispatchMode::ASYNC
$commandBus->dispatchSync($command);             // Shortcut for DispatchMode::SYNC
```

Queries support `dispatchSync()` for symmetry, while events mirror the command
API. Refer to the [configuration reference](reference.md#configuration-reference)
for the complete list of options that influence dispatch resolution.

### Toggling DispatchAfterCurrentBusStamp

Asynchronous commands and events automatically receive Messenger's
`DispatchAfterCurrentBusStamp` so they are queued after the current handler
finishes. You can turn this off globally or per message:

```yaml
somework_cqrs:
    async:
        dispatch_after_current_bus:
            command:
                default: true
                map:
                    App\Application\Command\ShipOrder: false
            event:
                default: true
```

With the override above `ShipOrder` commands are sent to the async bus
immediately, even if they are dispatched from inside another handler.

## Metadata providers and correlation IDs

Each dispatch can attach a `MessageMetadataStamp` carrying a correlation ID and
arbitrary key/value extras. The default
`RandomCorrelationMetadataProvider` generates a random identifier, which you can
read inside a handler:

```php
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;

final class ShipOrderHandler implements EnvelopeAware
{
    use EnvelopeAwareTrait;

    public function __invoke(ShipOrder $command): void
    {
        $envelope = $this->getEnvelope();
        $metadataStamp = $envelope?->last(MessageMetadataStamp::class);

        if ($metadataStamp) {
            $correlationId = $metadataStamp->getCorrelationId();
            // Pass $correlationId to your logger or tracing system…
        }
    }
}
```

To override the metadata provider for a specific message implement
`MessageMetadataProvider` and register it in the configuration:

```php
use SomeWork\CqrsBundle\Contract\DispatchMode;
use SomeWork\CqrsBundle\Contract\MessageMetadataProvider;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;

final class TenantCorrelationMetadataProvider implements MessageMetadataProvider
{
    public function getStamp(object $message, DispatchMode $mode): ?MessageMetadataStamp
    {
        return new MessageMetadataStamp(
            $message->tenantId,
            ['tenant' => $message->tenantId, 'mode' => $mode->value],
        );
    }
}
```

```yaml
somework_cqrs:
    metadata:
        command:
            map:
                App\Application\Command\ShipOrder: App\Support\TenantCorrelationMetadataProvider
```

Handlers now receive deterministic correlation IDs whenever `ShipOrder` is
dispatched. More metadata knobs – including per-type defaults and global
fallbacks – are covered in the [configuration reference](reference.md#configuration-reference).
