# Usage guide

This bundle layers a CQRS-friendly API on top of Symfony Messenger. It provides
attribute-based autoconfiguration, optional interfaces, and tooling to keep your
handler catalogue discoverable.

All examples assume that your handlers are services with `autoconfigure`
enabled, which is the default for everything in `src/` in a Symfony
application.

## Registering handlers with attributes

Annotate handlers with the provided attributes to register them as Messenger
handlers. Type the first parameter of `__invoke()` with the message class; the
return type is up to you (commands may return a value for `dispatchSync()`,
queries return their result, events return `void`).

```php
<?php

namespace App\Application\Command;

use SomeWork\CqrsBundle\Contract\Command;

final class ApproveInvoice implements Command
{
    public function __construct(
        public readonly string $invoiceId,
    ) {
    }
}
```

```php
<?php

namespace App\Application\Command;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;

#[AsCommandHandler(command: ApproveInvoice::class)]
final class ApproveInvoiceHandler
{
    public function __invoke(ApproveInvoice $command): mixed
    {
        // Handle the command…
        return null;
    }
}
```

The same pattern exists for queries (`#[AsQueryHandler(query: ...)]`) and
events (`#[AsEventHandler(event: ...)]`). All three attributes live in
`SomeWork\CqrsBundle\Attribute`, are repeatable, and accept an optional `bus`
argument:

* Without `bus`, the handler is registered on the synchronous bus of its type
  (`buses.command`, `buses.query`, or `buses.event`, falling back to
  `default_bus`) and, when one is configured, on the asynchronous bus of its
  type (`buses.command_async` or `buses.event_async`). Workers consuming
  asynchronous messages therefore find the handler without extra
  configuration.
* With `bus: 'my.bus'`, the handler is registered on that Messenger bus only.

Commands and queries must have exactly one handler. Two handlers for the same
command or query on the same bus make the container compilation fail, and so
does a handler registered for a parent class or interface of the message next
to the message's own handler (Messenger would run both). A missing handler is
reported when the message is dispatched. Events may have
any number of handlers.

### Fire-and-forget events

Messenger normally throws `NoHandlerForMessageException` when a message has no
handler. The bundle adds an internal middleware (`AllowNoHandlerMiddleware`) to
every configured event bus (`buses.event`, `buses.event_async`, or the
`default_bus` fallback). It catches the exception and returns the original
envelope when the message implements `SomeWork\CqrsBundle\Contract\Event`, so
you can publish integration or domain events before any projection subscribes
to them. Commands and queries keep Messenger's default behaviour so that gaps in
their handler catalogues still surface.

## Interface autoconfiguration

If you prefer interfaces over attributes, implement one of the handler marker
interfaces (`CommandHandler`, `QueryHandler`, `EventHandler` in
`SomeWork\CqrsBundle\Contract`) and type-hint the message on `__invoke()`. The
interfaces declare no method, because PHP does not allow an implementation to
narrow a parameter type; the compiler pass reads the type of the first
parameter of `__invoke()` to find out which message the handler is responsible
for. A union type (`__invoke(InvoicePaid|InvoiceVoided $event)`) registers the
handler for each member. Every member must match an interface the handler
implements: a process manager that implements `CommandHandler` and
`EventHandler` can accept `ShipOrder|OrderPaid`, while a `CommandHandler` that
also accepts an event fails the container compilation.

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

A handler that implements an interface but has no typed `__invoke()` parameter
makes the container compilation fail with a message asking you to add the type
or the attribute.

When wiring services manually with the `messenger.message_handler` tag you can
set the `method` attribute to point at a handler method other than `__invoke()`.
The compiler pass reflects that method to determine the message type when
`handles` is not provided.

The `handles` attribute accepts either a single message class string, an array
of class strings, or an associative array where the keys are the message
classes. Associative definitions let you pair classes with method names or
options understood by Messenger (for example `['method' => 'handle']` or
`['from_transport' => 'async']`). The bundle records the message classes from
either format so that its metadata and console tooling match what Messenger
registers.

## Attribute-only handlers

The marker interfaces are optional. A class annotated with the handler
attribute alone is discovered and registered:

```php
<?php

namespace App\Application\Command;

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

The attribute's `command`, `query`, or `event` argument declares the handled
message. The attribute type must match the marker interface the message class
implements (`#[AsCommandHandler]` for an event fails the container
compilation); for a message that implements none of them, the attribute type
decides. When a class has both the attribute and a handler marker interface,
the attribute defines the registration.

## Abstract base handlers

`SomeWork\CqrsBundle\Handler` provides base classes that implement the marker
interface and `EnvelopeAware` for you:

* `AbstractCommandHandler` -- implement `protected function handle(Command $command): mixed`.
* `AbstractQueryHandler` -- implement `protected function fetch(Query $query): mixed`.
* `AbstractEventHandler` -- implement `protected function on(Event $event): void`.

Their `__invoke()` is untyped, so combine them with the attribute to declare the
handled message. `$this->getEnvelope()` returns the Messenger envelope of the
message being handled.

```php
<?php

namespace App\Application\Command;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Handler\AbstractCommandHandler;

/** @extends AbstractCommandHandler<CancelOrder> */
#[AsCommandHandler(command: CancelOrder::class)]
final class CancelOrderHandler extends AbstractCommandHandler
{
    protected function handle(Command $command): mixed
    {
        \assert($command instanceof CancelOrder);

        // Cancel the order…
        return null;
    }
}
```

## Console tooling

The bundle registers these console commands:

* `somework:cqrs:list` -- the handler catalogue.
* `somework:cqrs:generate` -- scaffolds a message class and its handler.
* `somework:cqrs:debug-transports` -- the transport configuration of the bundle.
* `somework:cqrs:health` -- checks that handlers and transports can be built.
* `somework:cqrs:outbox:relay`, `somework:cqrs:outbox:setup`,
  `somework:cqrs:outbox:failed` and `somework:cqrs:outbox:purge` -- only when
  the transactional outbox is enabled; see [Transactional Outbox](outbox.md).

### Listing handlers

`somework:cqrs:list` prints one table per handler and bus, grouped into
commands, queries, and events. A handler registered on both a synchronous and
an asynchronous bus appears once per bus. Use `--type` (repeatable) to focus
the output; an unknown type exits with code 2.

```
$ bin/console somework:cqrs:list --type=command --type=query
```

Pass `--details` to inspect the configuration the bundle resolves for each
message (the transports shown come from the `transports` configuration; the
`#[Asynchronous]` attribute and `framework.messenger.routing` also send
messages to transports, see `somework:cqrs:debug-transports`):

```
$ bin/console somework:cqrs:list --type=command --details

Commands
--------

╔═══════════════════╤═══════════════════════════════════════════════════════════════╗
║ Field             │ Value                                                         ║
╠═══════════════════╪═══════════════════════════════════════════════════════════════╣
║ Type              │ Command                                                       ║
║ Message           │ CreateTask                                                    ║
║ Handler           │ App\Task\CreateTaskHandler                                    ║
║ Service Id        │ App\Task\CreateTaskHandler                                    ║
║ Bus               │ command.bus                                                   ║
║ Dispatch Mode     │ sync                                                          ║
║ Async Defers      │ yes                                                           ║
║ Sync Transports   │ None                                                          ║
║ Async Transports  │ async                                                         ║
║ Retry Policy      │ SomeWork\CqrsBundle\Support\NullRetryPolicy                   ║
║ Serializer        │ SomeWork\CqrsBundle\Support\NullMessageSerializer             ║
║ Metadata Provider │ SomeWork\CqrsBundle\Support\RandomCorrelationMetadataProvider ║
╚═══════════════════╧═══════════════════════════════════════════════════════════════╝
```

The detail rows correspond to the configuration resolved for the message:

* **Dispatch Mode** -- the mode used when callers dispatch with
  `DispatchMode::DEFAULT` (see
  [Choosing synchronous or asynchronous dispatch](#choosing-synchronous-or-asynchronous-dispatch)).
* **Async Defers** -- whether `DispatchAfterCurrentBusStamp` is added when the
  message is dispatched asynchronously. `n/a` appears for queries, which are
  always synchronous.
* **Sync Transports** / **Async Transports** -- the transport names from the
  `transports` configuration.
* **Retry Policy**, **Serializer**, and **Metadata Provider** -- the services
  the container selected for the message, allowing you to verify overrides at a
  glance.

The **Message** column uses the configured naming strategy (`naming`); the
default strategy shows the short class name.

`SomeWork\CqrsBundle\Registry\HandlerRegistry` holds the metadata behind the
command and offers `all()`, `byType()`, and `getDisplayName()`. It is part of
the public API; autowire it to inspect the registered handlers.

### Generating a message and its handler

`somework:cqrs:generate` takes the message type (`command`, `query`, or
`event`) and the fully-qualified class name of the message as arguments. Quote
the class name so that the shell keeps the backslashes:

```bash
bin/console somework:cqrs:generate command 'App\Application\Command\ShipOrder'
```

The files are placed according to the PSR-4 mapping in your `composer.json`
(a namespace that no PSR-4 prefix covers is refused unless `--dir` is given:
Composer could not load the classes, and the service import of `src/` would
fail),
so `App\Application\Command\ShipOrder` becomes
`src/Application/Command/ShipOrder.php` and
`src/Application/Command/ShipOrderHandler.php`. The generated message
implements the marker interface; the generated handler carries the matching
attribute and a typed `__invoke()`. Options:

* `--handler` -- fully-qualified class name of the handler (defaults to the
  message class name followed by `Handler`).
* `--dir` -- directory, relative to the project directory, that replaces the
  directory mapped to the class's PSR-4 prefix. The target must stay inside the
  project.
* `--force` -- overwrite existing files instead of aborting.

### Inspecting transports

`somework:cqrs:debug-transports` prints, for each CQRS bus (command, async
command, query, event, async event), the default transport names and the
per-message overrides configured under `somework_cqrs.transports`. It does not
include `framework.messenger.routing` or transports chosen by the
`#[Asynchronous]` attribute; `bin/console debug:config framework messenger`
shows Messenger's own routing.

### Health checks

`somework:cqrs:health` instantiates every CQRS handler and every Messenger
transport and reports the findings in a table. The exit code is the highest
severity found: `0` (OK), `1` (warnings), or `2` (critical), which makes the
command usable in deployment checks. Add your own checks by implementing
`SomeWork\CqrsBundle\Health\HealthChecker`; its `check()` method returns a list
of `CheckResult(CheckSeverity $severity, string $category, string $message)`
objects, and autoconfigured services are picked up automatically.

See the [configuration reference](reference.md) for the exhaustive list of
options.

## Messenger integration

The bundle does not replace Messenger configuration. Configure your buses and
transports under `framework.messenger` as usual and point the CQRS buses at
them with `somework_cqrs.buses`. See the [configuration reference](reference.md)
for the list of options.

Handlers that implement `SomeWork\CqrsBundle\Contract\EnvelopeAware` (for
example by using the bundled `EnvelopeAwareTrait`) receive the current
Messenger `Envelope` before execution. The bundle decorates the handlers locator
of each configured CQRS bus so that `setEnvelope()` is called for both
synchronous and asynchronous handling, allowing you to access stamps and
metadata via `$this->getEnvelope()`.

## Choosing synchronous or asynchronous dispatch

`CommandBus::dispatch()` and `EventBus::dispatch()` accept an optional
`SomeWork\CqrsBundle\Bus\DispatchMode` argument with the cases `SYNC`, `ASYNC`,
and `DEFAULT` (the default). `SYNC` and `ASYNC` are used as given. For
`DEFAULT`, the bundle resolves the mode per message class, first match wins:

1. An entry for the exact message class in `dispatch_modes.<type>.map`.
2. The `#[Asynchronous]` attribute on the message class itself (PHP
   attributes are not inherited), which selects `async`.
3. An entry in `dispatch_modes.<type>.map` for a parent class (nearest first),
   then for an implemented interface (most specific first).
4. `dispatch_modes.<type>.default` (`sync` unless configured otherwise).

Queries are always synchronous. The following configuration keeps most commands
synchronous while routing `ShipOrder` asynchronously:

```yaml
# config/packages/somework_cqrs.yaml
somework_cqrs:
    buses:
        command_async: command.async_bus
    dispatch_modes:
        command:
            default: sync
            map:
                App\Application\Command\ShipOrder: async
```

Map keys must be existing classes or interfaces; a typo makes the container
compilation fail. Asynchronous dispatch modes require the matching async bus
(`buses.command_async` or `buses.event_async`); without it the configuration is
rejected at compile time.

At runtime you can still make an explicit choice:

```php
use SomeWork\CqrsBundle\Bus\DispatchMode;

$commandBus->dispatch($command);                     // Uses the resolved mode
$commandBus->dispatch($command, DispatchMode::ASYNC);
$commandBus->dispatchAsync($command);                // Always asynchronous
$result = $commandBus->dispatchSync($command);       // Always synchronous, returns the handler result
```

`EventBus` has the same `dispatch()`, `dispatchSync()`, and `dispatchAsync()`
methods; all three return the envelope. Dispatching asynchronously when no
async bus is configured for the message type throws
`SomeWork\CqrsBundle\Exception\AsyncBusNotConfiguredException`.

The asynchronous bus only decides which Messenger bus handles the message. To
actually send it to a transport, configure `transports.command_async` /
`transports.event_async` (or use `#[Asynchronous]`, which names a transport);
without a transport name or a `framework.messenger.routing` entry the message
is handled right away on the asynchronous bus.

### Toggling DispatchAfterCurrentBusStamp

Asynchronous commands and events automatically receive Messenger's
`DispatchAfterCurrentBusStamp`. When such a message is dispatched while another
message is being handled (for example from inside a command handler), Messenger
holds it back until the outer handler has finished successfully and drops it if
the handler fails. You can turn this off globally or per message:

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
immediately, even if they are dispatched from inside another handler. The
configuration only controls the automatic stamp: a `DispatchAfterCurrentBusStamp`
you pass yourself is always kept by `dispatch()`. `dispatchSync()` and `ask()`
drop the stamp because they need the result immediately.

## Dispatching commands

Inject `CommandBusInterface` and call `dispatch()` to send a command to its
handler. `dispatch()` returns the Messenger `Envelope`:

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

`dispatchSync()` forces synchronous handling and returns the handler result
directly. `dispatchAsync()` dispatches the command on the asynchronous command
bus (`buses.command_async`) and returns the envelope.

### Passing stamps

Every dispatch method accepts additional Messenger stamps as trailing
arguments:

```php
use SomeWork\CqrsBundle\Bus\DispatchMode;
use Symfony\Component\Messenger\Stamp\DelayStamp;

$commandBus->dispatch($command, DispatchMode::DEFAULT, new DelayStamp(5000));
$commandBus->dispatchAsync($command, new DelayStamp(5000));
$result = $queryBus->ask($query, new MyStamp());
```

Stamps you pass win over the stamp pipeline: an explicit
`MessageMetadataStamp`, `SerializerStamp`, `TransportNamesStamp`,
`AggregateSequenceStamp`, `DeduplicateStamp`, or `DispatchAfterCurrentBusStamp`
is kept instead of the configured one.

## Exceptions from dispatchSync() and ask()

`CommandBusInterface::dispatchSync()` and `QueryBusInterface::ask()` need a
handler result, so they report every situation in which there is none. The
exceptions live in `SomeWork\CqrsBundle\Exception`:

| Exception | Thrown when |
|---|---|
| `NoHandlerException` | No handler handled the message on the bus (Messenger's `NoHandlerForMessageException` is converted and kept as the previous exception). A missing handler of a message dispatched *inside* a handler is not converted. |
| `MultipleHandlersException` | More than one handler handled the message, so the result is ambiguous (for example a catch-all handler of an interface next to the message's own handler). The handlers have already run, so do not simply retry. |
| `MessageSentToTransportException` | The message was sent to a transport instead of being handled, for example because of `framework.messenger.routing` or a `transports.command` / `transports.query` entry. It is queued and a worker will handle it: do not dispatch it again. |
| `DuplicateMessageException` | Idempotency deduplication dropped the message as a duplicate. |
| `RateLimitExceededException` | A rate limiter mapped to the message has no tokens left (thrown by every dispatch method). |

`AsyncBusNotConfiguredException` is thrown by asynchronous dispatches
(`dispatchAsync()`, `DispatchMode::ASYNC`, or a `DEFAULT` that resolves to
`async`) when no async bus is configured for the message type.

When exactly one handler throws, `dispatchSync()` and `ask()` rethrow that
exception as is instead of wrapping it in Messenger's
`HandlerFailedException`, so you can catch your domain exceptions directly:

```php
try {
    $orderId = $commandBus->dispatchSync(new CreateOrder($items));
} catch (OutOfStockException $exception) {
    // Thrown by the handler
}
```

`dispatch()` and the event bus methods keep Messenger's behaviour: exceptions
thrown by synchronously handled messages arrive wrapped in
`HandlerFailedException`.

## Asking queries

`QueryBusInterface` exposes a single `ask()` method that is always synchronous
and returns the handler result:

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

The query bus enforces exactly one handler per query: a second handler on the
same bus is rejected at compile time, and `ask()` throws the exceptions listed
in [Exceptions from dispatchSync() and ask()](#exceptions-from-dispatchsync-and-ask)
when there is no single result.

## Dispatching events

Events support zero to many handlers and are fire-and-forget. Use
`EventBusInterface` to dispatch domain events, for example from a command
handler:

```php
<?php

namespace App\Application\Command;

use App\Domain\Event\InvoiceApproved;
use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Contract\EventBusInterface;

#[AsCommandHandler(command: ApproveInvoice::class)]
final class ApproveInvoiceHandler
{
    public function __construct(
        private readonly EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(ApproveInvoice $command): mixed
    {
        // ... approve the invoice ...

        $this->eventBus->dispatch(new InvoiceApproved($command->invoiceId));

        return null;
    }
}
```

Events dispatched without any registered handler do not throw an exception
(see [Fire-and-forget events](#fire-and-forget-events)).

Multiple handlers can subscribe to the same event; each is a class of its own:

```php
<?php

namespace App\Application\Event;

use App\Domain\Event\InvoiceApproved;
use SomeWork\CqrsBundle\Attribute\AsEventHandler;

#[AsEventHandler(event: InvoiceApproved::class)]
final class SendApprovalNotification
{
    public function __invoke(InvoiceApproved $event): void
    {
        // Notify the customer…
    }
}

#[AsEventHandler(event: InvoiceApproved::class)]
final class UpdateApprovalDashboard
{
    public function __invoke(InvoiceApproved $event): void
    {
        // Refresh the dashboard…
    }
}
```

## Async routing with the #[Asynchronous] attribute

Instead of configuring the dispatch mode in YAML, you can annotate a message
class with `#[Asynchronous]`:

```php
<?php

namespace App\Application\Command;

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

The attribute has two effects when the message is dispatched with
`DispatchMode::DEFAULT`:

* The dispatch mode resolves to `async` (unless `dispatch_modes.<type>.map`
  has an entry for this exact class), so the message goes to the asynchronous
  bus. An async bus must be configured (`buses.command_async` or
  `buses.event_async`).
* On asynchronous dispatches it chooses the transport. A `TransportNamesStamp`
  passed by the caller and an entry for exactly this class in
  `transports.command_async.map` / `transports.event_async.map` win; next comes
  the attribute's `transport`, then entries for parent classes or interfaces and
  the section's `default`. A bare `#[Asynchronous]` (no `transport`) falls back
  to the `async` transport only when nothing is configured and
  `framework.messenger.routing` does not route the message.

The default transport name is `async`. Pass a custom transport name when your
infrastructure uses a different name:

```php
#[Asynchronous(transport: 'notifications')]
final class SendWelcomeEmail implements Command { /* ... */ }
```

When the message has a handler in the application, the container compilation
checks that the transport exists. A route in `framework.messenger.routing`
counts as routing the message when it names the class, a parent class, an
interface, a namespace wildcard (`App\Message\*`) or `*`.

`dispatchSync()` still handles an `#[Asynchronous]` message synchronously.

## Metadata providers and correlation IDs

Each dispatch can attach a `MessageMetadataStamp` carrying a correlation ID,
an optional causation ID, and arbitrary key/value extras. The default
`RandomCorrelationMetadataProvider` generates a random correlation ID, which you
can read inside a handler:

```php
<?php

namespace App\Application\Command;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;

#[AsCommandHandler(command: ShipOrder::class)]
final class ShipOrderHandler implements EnvelopeAware
{
    use EnvelopeAwareTrait;

    public function __invoke(ShipOrder $command): mixed
    {
        $metadataStamp = $this->getEnvelope()->last(MessageMetadataStamp::class);

        if ($metadataStamp instanceof MessageMetadataStamp) {
            $correlationId = $metadataStamp->getCorrelationId();
            $causationId = $metadataStamp->getCausationId(); // correlation ID of the parent message, if any
            // Pass the IDs to your logger or tracing system…
        }

        return null;
    }
}
```

When a message is dispatched while another one is being handled, the causation
ID of the new message is set to the correlation ID of the message being handled
(`causation_id` configuration).

To change the metadata for a specific message, implement
`MessageMetadataProvider` and register it in the configuration. The example
assumes a `ShipOrder` command with a `tenantId` property:

```php
<?php

namespace App\Support;

use App\Application\Command\ShipOrder;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\MessageMetadataProvider;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;

final class TenantMetadataProvider implements MessageMetadataProvider
{
    public function getStamp(object $message, DispatchMode $mode): ?MessageMetadataStamp
    {
        $stamp = MessageMetadataStamp::createWithRandomCorrelationId(['mode' => $mode->value]);

        if ($message instanceof ShipOrder) {
            $stamp = $stamp->withExtra('tenant', $message->tenantId);
        }

        return $stamp;
    }
}
```

```yaml
somework_cqrs:
    metadata:
        command:
            map:
                App\Application\Command\ShipOrder: App\Support\TenantMetadataProvider
```

The value is a service id; with the default service configuration the class
name is the id. Returning `null` from `getStamp()` dispatches the message
without metadata, and a `MessageMetadataStamp` passed by the caller is kept
instead of the provider's. Per-type defaults (`metadata.command.default`) and
the global fallback (`metadata.default`) are covered in the
[configuration reference](reference.md).
