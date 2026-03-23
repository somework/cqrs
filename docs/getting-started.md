# Getting Started

This tutorial walks you through installing the bundle and progressively using its features -- from dispatching your first command to async routing, testing, and advanced patterns.

## Installation

### With Symfony Flex (recommended)

```bash
composer require somework/cqrs-bundle
```

Flex automatically registers the bundle in `config/bundles.php` and creates a commented `config/packages/somework_cqrs.yaml` with all available options.

### Without Symfony Flex

```bash
composer require somework/cqrs-bundle
```

Register the bundle manually in `config/bundles.php`:

```php
return [
    // ...
    SomeWork\CqrsBundle\SomeWorkCqrsBundle::class => ['all' => true],
];
```

Create `config/packages/somework_cqrs.yaml` (see `docs/flex-recipe/` for a template).

### Verify the installation

```bash
bin/console somework:cqrs:list
```

If the bundle is registered, this command prints an empty handler table. You are ready to create your first command.

## First command

### Define a command message

Commands are immutable DTOs that describe an intent. They implement the `Command` marker interface:

```php
<?php

declare(strict_types=1);

namespace App\Application\Command;

use SomeWork\CqrsBundle\Contract\Command;

final class CreateTask implements Command
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
    }
}
```

### Create a handler

Handlers process a single message type. Annotate them with `#[AsCommandHandler]` for automatic registration:

```php
<?php

declare(strict_types=1);

namespace App\Application\Command;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Contract\CommandHandler;

#[AsCommandHandler(command: CreateTask::class)]
final class CreateTaskHandler implements CommandHandler
{
    public function __construct(
        private readonly TaskRepository $tasks,
    ) {
    }

    public function __invoke(CreateTask $command): mixed
    {
        $this->tasks->save(new Task($command->id, $command->name));

        return null;
    }
}
```

!!! tip "Handler interfaces are optional"
    You can omit `implements CommandHandler` and rely on the attribute alone. The bundle discovers handlers either way.

### Dispatch from a controller

Inject `CommandBusInterface` (or the concrete `CommandBus`) and dispatch:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Command\CreateTask;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class TaskController
{
    #[Route('/tasks', methods: ['POST'])]
    public function create(Request $request, CommandBusInterface $commandBus): JsonResponse
    {
        $data = $request->toArray();

        $commandBus->dispatch(new CreateTask(
            id: uuid_create(),
            name: $data['name'],
        ));

        return new JsonResponse(['status' => 'ok'], 201);
    }
}
```

Run `bin/console somework:cqrs:list` again to see your new handler in the catalogue.

## Queries and events

### QueryBus

Queries request data and always return a result. They implement the `Query` marker interface:

```php
<?php

declare(strict_types=1);

namespace App\ReadModel\Query;

use SomeWork\CqrsBundle\Contract\Query;

final class FindTask implements Query
{
    public function __construct(
        public readonly string $id,
    ) {
    }
}
```

The handler returns the result:

```php
<?php

declare(strict_types=1);

namespace App\ReadModel\Query;

use SomeWork\CqrsBundle\Attribute\AsQueryHandler;
use SomeWork\CqrsBundle\Contract\QueryHandler;

#[AsQueryHandler(query: FindTask::class)]
final class FindTaskHandler implements QueryHandler
{
    public function __construct(
        private readonly TaskRepository $tasks,
    ) {
    }

    public function __invoke(FindTask $query): mixed
    {
        return $this->tasks->find($query->id);
    }
}
```

Dispatch with `QueryBusInterface::ask()`:

```php
$task = $queryBus->ask(new FindTask(id: $id));
```

`ask()` validates that exactly one handler processed the query and returns its result. Zero handlers or multiple handlers both throw an exception.

### EventBus

Events describe facts that have already happened. They implement the `Event` marker interface and can have zero to many handlers:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Event;

use SomeWork\CqrsBundle\Contract\Event;

final class TaskCreated implements Event
{
    public function __construct(
        public readonly string $taskId,
        public readonly string $taskName,
    ) {
    }
}
```

Multiple handlers can listen to the same event:

```php
<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Event\TaskCreated;
use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Contract\EventHandler;

#[AsEventHandler(event: TaskCreated::class)]
final class SendTaskNotification implements EventHandler
{
    public function __invoke(TaskCreated $event): void
    {
        // Send notification...
    }
}
```

Dispatch with `EventBusInterface::dispatch()`:

```php
$eventBus->dispatch(new TaskCreated(taskId: $id, taskName: $name));
```

Events are fire-and-forget. Missing handlers are silently tolerated.

## Async dispatch

By default, all messages are dispatched synchronously. The bundle provides several ways to route messages asynchronously.

### Using DispatchMode

Pass `DispatchMode::ASYNC` when dispatching:

```php
use SomeWork\CqrsBundle\Bus\DispatchMode;

$commandBus->dispatch($command, DispatchMode::ASYNC);
```

This requires an async bus to be configured for that message type in `somework_cqrs.yaml`:

```yaml
somework_cqrs:
    buses:
        command_async: messenger.bus.commands_async
```

### Using the #[Asynchronous] attribute

Annotate a message class to always route it through the async bus:

```php
use SomeWork\CqrsBundle\Attribute\Asynchronous;
use SomeWork\CqrsBundle\Contract\Command;

#[Asynchronous]
final class GenerateReport implements Command
{
    public function __construct(
        public readonly string $reportId,
    ) {
    }
}
```

### Using dispatch_modes configuration

Configure per-message defaults in YAML:

```yaml
somework_cqrs:
    dispatch_modes:
        command:
            default: sync
            map:
                App\Application\Command\GenerateReport: async
```

### Transport configuration

Configure Messenger transports and routing as usual:

```yaml
framework:
    messenger:
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            'App\Application\Command\GenerateReport': async
```

Run the worker: `bin/console messenger:consume async`.

## Testing

The bundle ships fake bus implementations for unit testing without Messenger.

### FakeBus setup

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Command\CreateTask;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;
use PHPUnit\Framework\TestCase;

final class TaskServiceTest extends TestCase
{
    public function testCreateTaskDispatchesCommand(): void
    {
        $bus = new FakeCommandBus();
        $service = new TaskService($bus);

        $service->createTask('task-1', 'My Task');

        FakeCommandBus::assertDispatched($bus, CreateTask::class);
    }
}
```

### Callback assertions

Assert specific properties on dispatched messages:

```php
FakeCommandBus::assertDispatched(
    $bus,
    CreateTask::class,
    fn(CreateTask $cmd) => $cmd->name === 'My Task',
);
```

### Assert not dispatched

```php
FakeCommandBus::assertNotDispatched($bus, CreateTask::class);
```

### Query bus testing

Configure return values for queries:

```php
$queryBus = new FakeQueryBus();
$queryBus->willReturn(FindTask::class, $expectedTask);

$result = $queryBus->ask(new FindTask(id: 'task-1'));
// $result === $expectedTask
```

For more details, see the [Testing Guide](testing.md).

## Advanced patterns

The bundle supports several production-grade patterns. Each has a dedicated documentation page:

- **[Retry Policies](retry.md)** -- Configure per-message retry strategies with exponential backoff and transport-level integration.
- **[Transactional Outbox](outbox.md)** -- Store messages in a database table and relay them to Messenger transports for guaranteed delivery.
- **[Event Ordering](event-ordering.md)** -- Maintain sequence numbers per aggregate using `SequenceAware` and `AggregateSequenceStamp`.
- **[Idempotency](idempotency.md)** -- Bridge `IdempotencyStamp` to Symfony's `DeduplicateStamp` to prevent duplicate processing.
- **[Rate Limiting](rate-limiting.md)** -- Throttle message dispatch per type using Symfony Rate Limiter.

### Custom StampDeciders

The `StampDecider` interface is marked `@api` -- you can implement your own and register it via the DI tag:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Cqrs;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Support\StampDecider;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class AuditTrailStampDecider implements StampDecider
{
    /** @param array<int, StampInterface> $stamps */
    /** @return array<int, StampInterface> */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        $stamps[] = new AuditTrailStamp(
            userId: $this->security->getUser()?->getId(),
            timestamp: new \DateTimeImmutable(),
        );

        return $stamps;
    }
}
```

Register it in your services configuration:

```yaml
services:
    App\Infrastructure\Cqrs\AuditTrailStampDecider:
        tags:
            - { name: 'somework_cqrs.dispatch_stamp_decider', priority: 100 }
```

### OpenTelemetry

Install `open-telemetry/api` to enable automatic trace spans for message dispatch and handler execution:

```bash
composer require open-telemetry/api
```

The bundle automatically registers `OpenTelemetryMiddleware` when the OpenTelemetry API is available. See the [Production Guide](production.md) for configuration details.
