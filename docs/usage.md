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
$result = $commandBus->dispatchSync($command);   // Shortcut for DispatchMode::SYNC, returns handler result
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

## Dispatching commands

Inject `CommandBusInterface` and call `dispatch()` to send a command to its
handler. The bus returns a Messenger `Envelope` by default:

```php
<?php

namespace App\Controller;

use App\Application\Command\ApproveInvoice;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use Symfony\Component\HttpFoundation\Response;

final class InvoiceController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    public function approve(string $invoiceId): Response
    {
        $this->commandBus->dispatch(new ApproveInvoice($invoiceId));

        return new Response('', Response::HTTP_ACCEPTED);
    }
}
```

When you need the handler's return value (for example a server-generated ID),
use `dispatchSync()`:

```php
$orderId = $this->commandBus->dispatchSync(new CreateOrder($items));
```

`dispatchSync()` forces synchronous execution and returns the handler result
directly. `dispatchAsync()` routes the command to the configured async
transport.

## Asking queries

`QueryBusInterface` exposes a single `ask()` method that is always synchronous
and always returns the handler result:

```php
<?php

namespace App\Controller;

use App\ReadModel\Query\FindInvoice;
use SomeWork\CqrsBundle\Contract\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

final class InvoiceApiController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function show(string $invoiceId): JsonResponse
    {
        $invoice = $this->queryBus->ask(new FindInvoice($invoiceId));

        return new JsonResponse($invoice);
    }
}
```

The query bus enforces exactly one handler per query. Zero handlers or multiple
handlers both throw an exception at dispatch time.

## Dispatching events

Events support zero to many handlers and are fire-and-forget. Use
`EventBusInterface` to dispatch domain events:

```php
<?php

namespace App\Application\Command;

use App\Domain\Event\InvoiceApproved;
use SomeWork\CqrsBundle\Contract\EventBusInterface;

final class ApproveInvoiceHandler
{
    public function __construct(
        private readonly EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(ApproveInvoice $command): void
    {
        // ... approve the invoice ...

        $this->eventBus->dispatch(new InvoiceApproved($command->invoiceId));
    }
}
```

Events dispatched without any registered listener will not throw an exception.
The bundle's `AllowNoHandlerMiddleware` silences `NoHandlerForMessageException`
for `Event` instances automatically.

Multiple handlers can subscribe to the same event:

```php
#[AsEventHandler(event: InvoiceApproved::class)]
final class SendApprovalNotification { /* ... */ }

#[AsEventHandler(event: InvoiceApproved::class)]
final class UpdateApprovalDashboard { /* ... */ }
```

## Async routing with the #[Asynchronous] attribute

Instead of configuring transport routing in YAML, you can annotate a message
class with `#[Asynchronous]` to route it to the async transport automatically:

```php
<?php

use SomeWork\CqrsBundle\Attribute\Asynchronous;
use SomeWork\CqrsBundle\Contract\Command;

#[Asynchronous]
final class SendWelcomeEmail implements Command
{
    public function __construct(
        public readonly string $userId,
    ) {
    }
}
```

The default transport name is `async`. Pass a custom transport name when your
infrastructure uses a different name:

```php
#[Asynchronous(transport: 'notifications')]
final class SendWelcomeEmail implements Command { /* ... */ }
```

The `AsynchronousStampDecider` reads this attribute at dispatch time and adds a
`TransportNamesStamp`. It only applies when the dispatch mode is not `SYNC`, and
it yields to any `TransportNamesStamp` already present in the stamps array.

## Attribute-only handlers

Since v0.4.0 the marker interfaces (`CommandHandler`, `QueryHandler`,
`EventHandler`) are optional. A class annotated with the handler attribute alone
is auto-discovered and registered:

```php
<?php

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;

#[AsCommandHandler(command: CreateTask::class)]
final class CreateTaskHandler
{
    public function __invoke(CreateTask $command): mixed
    {
        // Handle the command
        return null;
    }
}
```

The compiler pass infers the message type from the attribute's `command`,
`query`, or `event` parameter. When both an attribute and a marker interface are
present, the interface takes priority for type classification.

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
