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

## Interface autoconfiguration

If you prefer interfaces over attributes the bundle can still discover your
handlers. Implement one of the marker interfaces and type-hint the message on
`__invoke()`. The compiler pass inspects the argument type to figure out which
message the handler is responsible for.

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

Two commands ship with the bundle once it is registered in your kernel:

* `somework:cqrs:list` renders a table of discovered messages and their
  handlers. Use `--type=command` (or `query` / `event`) to focus the output.
* `somework:cqrs:generate` scaffolds a message class and handler skeleton. Run
  `bin/console somework:cqrs:generate command App\Application\Command\ShipOrder`
  to produce `ShipOrder` and `ShipOrderHandler` inside your project `src/`
  directory. Pass `--dir=app/src` and `--force` to customise the target or
  overwrite existing files.

These commands respect the naming strategy configured for the bundle when
presenting handler information.

## Messenger integration

The bundle does not replace Messenger configuration. Configure your buses and
transports as usual and wire the CQRS buses to the appropriate Messenger buses.
See the reference documentation for the list of configurable options.
