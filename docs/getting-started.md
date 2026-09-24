# Getting Started

This tutorial walks you through installing the bundle and progressively using its features -- from dispatching your first command to asynchronous handling, testing, and advanced patterns.

## Installation

The bundle requires PHP 8.2 or newer and Symfony 7.2 or newer, including 8.x.

```bash
composer require somework/cqrs-bundle
```

### With Symfony Flex

Flex adds the bundle to `config/bundles.php` automatically. The Flex recipe that would also create `config/packages/somework_cqrs.yaml` is not published yet, so create that file yourself when you want to change the defaults (see [Configuration](#configuration) below).

### Without Symfony Flex

Register the bundle manually in `config/bundles.php`:

```php
return [
    // ...
    SomeWork\CqrsBundle\SomeWorkCqrsBundle::class => ['all' => true],
];
```

### Configuration

No configuration is required. By default the command, query, and event buses all use Messenger's default bus and every message is handled synchronously. To customise the bundle, create `config/packages/somework_cqrs.yaml`. The repository contains a commented template with every option in `docs/flex-recipe/`, and the container can print the full reference:

```bash
bin/console config:dump-reference somework_cqrs
```

See the [Configuration Reference](reference.md) for a description of every option.

### Verify the installation

```bash
bin/console somework:cqrs:list
```

If the bundle is registered, the command warns that no CQRS handlers were found yet. You are ready to create your first command.

## First command

The examples assume the default `config/services.yaml` of a Symfony application, which registers every class in `src/` as a service with `autowire` and `autoconfigure` enabled. Autoconfiguration is what lets the bundle discover your handlers.

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

A handler processes a single message type. Annotate it with `#[AsCommandHandler]` and type the first parameter of `__invoke()` with the command class. `TaskRepository` and `Task` stand for your own persistence code:

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
    You can omit `implements CommandHandler` and rely on the attribute alone, or implement the interface without the attribute: the bundle then reads the handled command from the type of the `__invoke()` parameter. The handler is registered on the command bus and, when `buses.command_async` is configured, on the asynchronous command bus as well.

### Dispatch from a controller

Inject `CommandBusInterface` (autowired to the bundle's `CommandBus`) and dispatch:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Command\CreateTask;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class TaskController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route('/tasks', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $id = bin2hex(random_bytes(8));

        $this->commandBus->dispatch(new CreateTask(
            id: $id,
            name: $request->getPayload()->getString('name'),
        ));

        return $this->json(['id' => $id], 201);
    }
}
```

`dispatch()` returns the Messenger `Envelope`. When you need the value returned by the handler, call `dispatchSync()` instead: it always handles the command synchronously and returns the handler result.

Run `bin/console somework:cqrs:list` again to see your new handler in the catalogue.

## Queries and events

### QueryBus

Queries request data. They implement the `Query` marker interface:

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

Ask the query through `QueryBusInterface::ask()`:

```php
$task = $queryBus->ask(new FindTask(id: $id));
```

Queries are always handled synchronously. `ask()` returns the result of the single handler; if the query was not handled by exactly one handler it throws an exception instead (see [Exceptions from dispatchSync() and ask()](usage.md#exceptions-from-dispatchsync-and-ask)). A second handler for the same query on the same bus is already rejected when the container is compiled.

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

Each listener is a handler class of its own:

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

Events are fire-and-forget: the bus returns the envelope, and an event without any handler is silently accepted.

## Async dispatch

By default, all messages are handled synchronously. To handle commands or events in a worker you need an asynchronous Messenger bus, a transport, and the matching bundle configuration.

### Configure the buses and the transport

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        default_bus: command.bus
        buses:
            command.bus: ~
            command.async_bus: ~
            event.bus: ~
            event.async_bus: ~
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
```

```yaml
# config/packages/somework_cqrs.yaml
somework_cqrs:
    buses:
        command: command.bus
        command_async: command.async_bus
        event: event.bus
        event_async: event.async_bus
    transports:
        command_async:
            default: [async]
        event_async:
            default: [async]
```

`transports.command_async` and `transports.event_async` make the bundle add a `TransportNamesStamp` to asynchronous dispatches, so no `framework.messenger.routing` entry is needed. Prefer them over `framework.messenger.routing` for CQRS messages: Messenger's routing applies on every bus, so a routed message is sent to the transport even when it is dispatched synchronously (and `dispatchSync()` then throws `MessageSentToTransportException`).

Handlers without an explicit `bus` are registered on both the synchronous and the asynchronous bus of their type, so no extra handler configuration is required.

### Choose asynchronous handling

There are three ways to send a message to the asynchronous bus.

**Per call** -- pass `DispatchMode::ASYNC` or use the shortcut:

```php
use SomeWork\CqrsBundle\Bus\DispatchMode;

$commandBus->dispatch($command, DispatchMode::ASYNC);
$commandBus->dispatchAsync($command);
```

**Per message class** -- mark the class with `#[Asynchronous]`; a plain `dispatch()` then goes to the asynchronous bus:

```php
<?php

declare(strict_types=1);

namespace App\Application\Command;

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

The attribute also adds a `TransportNamesStamp` for the transport named `async`; pass `#[Asynchronous(transport: 'reports')]` to use another transport.

**Per configuration** -- map classes (or their parent classes and interfaces) to a dispatch mode:

```yaml
somework_cqrs:
    dispatch_modes:
        command:
            default: sync
            map:
                App\Application\Command\GenerateReport: async
```

The [Usage Guide](usage.md#choosing-synchronous-or-asynchronous-dispatch) explains how these settings are combined. Asynchronous dispatch without a configured async bus fails: at compile time when the configuration asks for it, and with an `AsyncBusNotConfiguredException` at runtime otherwise.

### Run the worker

```bash
bin/console messenger:consume async
```

The worker handles each message on the asynchronous bus it was dispatched on.

## Testing

The bundle ships fake bus implementations and PHPUnit assertions for unit tests without Messenger. They live in `SomeWork\CqrsBundle\Testing`; the assertions require `phpunit/phpunit` in your application.

### Fake buses and assertions

Pass a fake bus to the code under test and assert on the recorded messages. `CqrsTestCase` extends PHPUnit's `TestCase` and provides `assertDispatched()` and `assertNotDispatched()`; if your test already extends another base class (such as `KernelTestCase`), use `CqrsAssertionsTrait` instead. `TaskService` stands for your own service that depends on `CommandBusInterface`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Command\CreateTask;
use App\Application\TaskService;
use SomeWork\CqrsBundle\Testing\CqrsTestCase;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;

final class TaskServiceTest extends CqrsTestCase
{
    public function testCreateTaskDispatchesCommand(): void
    {
        $bus = new FakeCommandBus();
        $service = new TaskService($bus);

        $service->createTask('task-1', 'My Task');

        self::assertDispatched($bus, CreateTask::class);
    }
}
```

### Callback assertions

Pass a callback to check properties of the dispatched message:

```php
self::assertDispatched(
    $bus,
    CreateTask::class,
    fn (CreateTask $command): bool => 'My Task' === $command->name,
);
```

### Assert not dispatched

```php
self::assertNotDispatched($bus, CreateTask::class);
```

### Configuring results

`FakeCommandBus::willReturn()` sets the value returned by `dispatchSync()`. `FakeQueryBus` returns a result per query class, or a default result for all other queries:

```php
$queryBus = new FakeQueryBus();
$queryBus->willReturnFor(FindTask::class, $expectedTask);
$queryBus->willReturn(null); // result for every other query

$result = $queryBus->ask(new FindTask(id: 'task-1'));
// $result === $expectedTask
```

All fakes expose `getDispatched()` and `reset()`. For more details, see the [Testing Guide](testing.md).

## Advanced patterns

Each of the following features has a dedicated page:

- **[Retry Policies](retry.md)** -- Configure per-message retry behaviour with exponential backoff and a transport-level retry strategy.
- **[Transactional Outbox](outbox.md)** -- Store messages in a database table within your transaction and relay them to Messenger transports.
- **[Event Ordering](event-ordering.md)** -- Attach per-aggregate sequence numbers to events with `SequenceAware` and `AggregateSequenceStamp`.
- **[Idempotency](idempotency.md)** -- Bridge `IdempotencyStamp` to Messenger's `DeduplicateStamp` to drop duplicate messages.
- **[Rate Limiting](rate-limiting.md)** -- Throttle dispatches per message class or interface using Symfony Rate Limiter.

### Custom StampDeciders

The `StampDecider` interface is marked `@api`: implement it to add your own stamps to every dispatch. The decider receives the message, the resolved dispatch mode (`SYNC` or `ASYNC`), and the stamps collected so far:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Cqrs;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Support\StampDecider;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Delays asynchronous messages by five seconds unless the caller set a delay.
 */
final class DelayAsyncMessagesStampDecider implements StampDecider
{
    /**
     * @param array<int, StampInterface> $stamps
     *
     * @return array<int, StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        if (DispatchMode::ASYNC !== $mode) {
            return $stamps;
        }

        foreach ($stamps as $stamp) {
            if ($stamp instanceof DelayStamp) {
                return $stamps;
            }
        }

        $stamps[] = new DelayStamp(5000);

        return $stamps;
    }
}
```

With autoconfiguration the class is added to the pipeline automatically, with priority 0, after the built-in deciders (225 to 50) and before `DispatchAfterCurrentBusStampDecider` (-10). To choose where it runs (higher priorities run first), tag it explicitly:

```yaml
services:
    App\Infrastructure\Cqrs\DelayAsyncMessagesStampDecider:
        tags:
            - { name: 'somework_cqrs.dispatch_stamp_decider', priority: 100 }
```

Implement `MessageTypeAwareStampDecider` instead to run the decider only for some message types. See [Middleware & Stamp Pipeline](middleware.md) for the built-in deciders and their priorities.

### OpenTelemetry

Install `open-telemetry/api` to get trace spans for message dispatch and for messages consumed by workers:

```bash
composer require open-telemetry/api
```

The bundle registers its `OpenTelemetryMiddleware` on the CQRS buses when the OpenTelemetry API is installed and the container has an `OpenTelemetry\API\Trace\TracerProviderInterface` service (provided by your OpenTelemetry SDK integration or defined yourself). See the [Production Guide](production.md) for monitoring advice.
