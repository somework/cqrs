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

## Messenger integration

The bundle does not replace Messenger configuration. Configure your buses and
transports as usual and wire the CQRS buses to the appropriate Messenger buses.
See the reference documentation for the list of configurable options.
