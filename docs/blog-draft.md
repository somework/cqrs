<!-- DRAFT -- set published: true before submitting to dev.to -->
---
title: "CQRS in Symfony: Raw Messenger vs somework/cqrs-bundle vs Ecotone"
published: false
description: "A practical comparison of three approaches to CQRS in Symfony, with code examples for each."
tags: php, symfony, cqrs, architecture
series: "CQRS in PHP"
---

Symfony Messenger is one of the best things to happen to PHP messaging. It gives you transports, retries, serialization, and a clean handler-per-message pattern. But when you start building CQRS -- separating commands from queries from events, each with distinct semantics -- Messenger leaves the wiring up to you.

I ran into this on a project where we needed per-message retry policies, typed bus interfaces for testing, and a clean way to route commands async while keeping queries synchronous. Messenger can do all of it, but you end up writing the same boilerplate across every project.

There are three paths I've seen teams take: build it yourself on raw Messenger, use a thin CQRS layer like `somework/cqrs-bundle`, or adopt a full CQRS/ES framework like Ecotone. Each makes different trade-offs, and the right choice depends on your project's needs.

## The problem: what raw Messenger leaves unsolved

Let's start with a concrete example. You have a task management system. You want to create tasks (command), query them (query), and react when they're created (event). Here's the message:

```php
namespace App\Application\Command;

final class CreateTask
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}
}
```

And the handler:

```php
namespace App\Application\Command;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CreateTaskHandler
{
    public function __invoke(CreateTask $command): void
    {
        // Save to database...
    }
}
```

This works. But now the questions start:

- **How do you separate command and query buses?** Messenger uses a single `MessageBusInterface`. You need to define multiple buses in `messenger.yaml` and tag handlers to the right one.
- **How do you get a return value from a command?** You dig into `HandledStamp` on the returned `Envelope`.
- **How do you configure per-message retry?** Retry is per-transport, not per-message. You need custom middleware or transport-level workarounds.
- **How do you test that a command was dispatched?** Messenger ships `InMemoryTransport`, but asserting on it means coupling your tests to transport internals.

None of these are unsolvable. But each one requires plumbing code that is the same across every Symfony CQRS project.

## Approach A: Raw Messenger

The raw approach works well for small apps or teams that want full control. Here's what it looks like done properly.

First, configure separate buses:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        default_bus: command.bus
        buses:
            command.bus:
                middleware:
                    - doctrine_transaction
            query.bus: ~
            event.bus:
                default_middleware:
                    allow_no_handlers: true
```

Define the message and handler:

```php
namespace App\Application\Command;

final class CreateTask
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}
}
```

```php
namespace App\Application\Command;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateTaskHandler
{
    public function __invoke(CreateTask $command): string
    {
        // Persist the task...
        return $command->id;
    }
}
```

Dispatch from a controller and extract the result:

```php
namespace App\Controller;

use App\Application\Command\CreateTask;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class TaskController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {}

    #[Route('/tasks', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $envelope = $this->commandBus->dispatch(new CreateTask(
            id: uuid_create(),
            name: $data['name'],
        ));

        $handledStamp = $envelope->last(HandledStamp::class);
        $taskId = $handledStamp?->getResult();

        return new JsonResponse(['id' => $taskId], 201);
    }
}
```

For testing, you use Messenger's `InMemoryTransport`:

```php
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TaskControllerTest extends KernelTestCase
{
    public function test_create_task(): void
    {
        // Boot kernel, get transport, dispatch, inspect transport messages...
        $transport = self::getContainer()->get('messenger.transport.async');
        // Assert on $transport->getSent()
    }
}
```

**The good:** zero additional dependencies, full control over every aspect, and Messenger's own documentation covers it. If you have simple dispatch needs and a small team that knows Messenger well, this is all you need.

**The friction:** result extraction via `HandledStamp`, manual bus tagging, per-transport (not per-message) retry configuration, and test assertions coupled to transport internals. Each of these is manageable individually, but they compound as the codebase grows.

## Approach B: somework/cqrs-bundle

The bundle provides a thin CQRS layer on top of Messenger. It does not replace Messenger -- it wires the patterns you would build yourself.

Same `CreateTask` message, now implementing the `Command` marker interface:

```php
namespace App\Application\Command;

use SomeWork\CqrsBundle\Contract\Command;

final class CreateTask implements Command
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}
}
```

The handler uses the bundle's attribute for auto-discovery:

```php
namespace App\Application\Command;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Contract\CommandHandler;

#[AsCommandHandler(command: CreateTask::class)]
final class CreateTaskHandler implements CommandHandler
{
    public function __invoke(CreateTask $command): mixed
    {
        // Persist the task...
        return $command->id;
    }
}
```

The controller injects a typed bus interface -- no `HandledStamp` extraction:

```php
namespace App\Controller;

use App\Application\Command\CreateTask;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class TaskController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {}

    #[Route('/tasks', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $taskId = $this->commandBus->dispatchSync(new CreateTask(
            id: uuid_create(),
            name: $data['name'],
        ));

        return new JsonResponse(['id' => $taskId], 201);
    }
}
```

Testing uses `FakeCommandBus` instead of transport internals:

```php
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Testing\CqrsAssertionsTrait;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;

final class TaskServiceTest extends TestCase
{
    use CqrsAssertionsTrait;

    public function test_creates_task(): void
    {
        $commandBus = new FakeCommandBus();
        $commandBus->willReturn('task-123');

        $service = new TaskService($commandBus);
        $result = $service->createTask('Write docs');

        self::assertDispatched($commandBus, CreateTask::class);
        self::assertSame('task-123', $result);
    }
}
```

**Key differences from raw Messenger:**

- **Auto-discovery**: `#[AsCommandHandler]`, `#[AsQueryHandler]`, `#[AsEventHandler]` register handlers to the correct bus automatically. No YAML tags.
- **Stamp pipeline**: A composable `StampDecider` pipeline attaches retry, transport, serializer, and metadata stamps per message class. Configure per-message retry without custom middleware.
- **Typed buses**: `CommandBus`, `QueryBus`, `EventBus` with distinct semantics. `dispatchSync()` returns the handler result directly. `QueryBus::ask()` enforces exactly one handler.
- **Testing utilities**: `FakeCommandBus`, `FakeQueryBus`, `FakeEventBus` with `assertDispatched()` and `assertNotDispatched()` for clean unit tests.

The bundle stays close to Messenger. Your handlers still receive Messenger envelopes. Your transports and routing still use `messenger.yaml`. The bundle adds structure, not abstraction.

## Approach C: Ecotone

Ecotone is a full CQRS/ES framework for PHP. Where the CQRS bundle adds a thin layer, Ecotone provides the entire architecture: command and query buses, event sourcing, sagas, process managers, and more.

Here's the same `CreateTask` flow in Ecotone:

```php
namespace App\Application\Command;

final class CreateTask
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}
}
```

```php
namespace App\Application\Command;

use Ecotone\Modelling\Attribute\CommandHandler;

final class TaskService
{
    #[CommandHandler]
    public function createTask(CreateTask $command): string
    {
        // Persist the task...
        return $command->id;
    }
}
```

Dispatching uses Ecotone's command bus gateway:

```php
namespace App\Controller;

use App\Application\Command\CreateTask;
use Ecotone\Modelling\CommandBus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class TaskController
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    #[Route('/tasks', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $taskId = $this->commandBus->send(new CreateTask(
            id: uuid_create(),
            name: $data['name'],
        ));

        return new JsonResponse(['id' => $taskId], 201);
    }
}
```

**Where Ecotone shines:** it provides sagas for long-running processes, built-in event sourcing with aggregate versioning, polled asynchronous endpoints, and interceptors (before/after/around) for cross-cutting concerns. If your domain requires event sourcing or saga orchestration, Ecotone gives you those patterns out of the box instead of building them yourself.

**The trade-off:** Ecotone introduces its own conventions and a framework layer beyond Messenger. The learning curve is steeper, and your codebase adopts Ecotone's patterns rather than staying pure Symfony. For teams that need the full CQRS/ES toolkit, that trade-off pays off. For teams that only need typed buses and per-message configuration, it may be more framework than necessary.

## Comparison table

| Capability | Raw Messenger | CQRS Bundle | Ecotone |
|---|---|---|---|
| **Handler discovery** | Manual YAML tags or `#[AsMessageHandler]` | `#[AsCommandHandler]` / `#[AsQueryHandler]` / `#[AsEventHandler]` with auto-discovery | Attribute-based with conventions |
| **Type safety** | Single `MessageBusInterface` | Separate `CommandBus`, `QueryBus`, `EventBus` with typed dispatch methods | Separate gateway interfaces |
| **Bus abstraction** | You build it | Three buses with sync/async routing, `DispatchMode` enum | Command/Query/Event buses built-in |
| **Retry configuration** | Per-transport YAML only | Per-message-class via `RetryPolicy` interface + resolver hierarchy | Per-endpoint via attributes |
| **Testing support** | `InMemoryTransport` | `FakeBus` implementations with `assertDispatched()` + callback assertions | Test support module |
| **Async routing** | `routing` YAML config | `DispatchMode` + `#[Asynchronous]` attribute + per-message transport mapping | Async via polled endpoints |
| **Stamp pipeline** | Manual stamp attachment | Composable `StampDecider` pipeline with priority ordering | Interceptors (before/after/around) |
| **Event ordering** | Not built-in | `SequenceAware` interface + `AggregateSequenceStamp` | Built-in aggregate versioning |
| **Transactional outbox** | Not built-in | `OutboxStorage` interface + DBAL implementation | Built-in with Doctrine |
| **Sagas / Process managers** | Not built-in | Not built-in | Built-in saga support |
| **Event sourcing** | Not built-in | Not built-in | Built-in event sourcing |
| **OpenTelemetry** | Not built-in | Bridge middleware with trace spans | Not built-in |
| **Learning curve** | Low (part of Symfony) | Low (thin layer over Messenger) | Moderate (own conventions) |
| **Dependencies** | Symfony only | Symfony Messenger | Ecotone framework |

Each row represents a practical decision point. **Handler discovery** determines how much YAML you maintain. **Retry configuration** matters when different messages have different failure characteristics (a payment command needs aggressive retries; a notification can fail silently). **Testing support** affects how fast you can write and run unit tests -- FakeBus assertions run in milliseconds without a container, while `InMemoryTransport` requires booting the kernel.

## When to choose each

**Choose raw Messenger** when your app has simple dispatch needs and you want zero additional dependencies. A small API with a few async jobs -- sending emails, processing uploads -- works perfectly with Messenger alone. You know the framework well, you don't need per-message retry, and you prefer explicit control over every configuration line. In our case, a microservice with three message types and no cross-cutting policies was a good fit for raw Messenger.

**Choose somework/cqrs-bundle** when you want structured CQRS buses, per-message configuration, and testing utilities while staying close to Messenger. A mid-size application with ten or more message types, mixed sync/async dispatch, and a team that values fast unit tests benefits from the bundle's typed buses and `FakeBus` assertions. If you find yourself writing the same stamp-attachment and bus-separation boilerplate in every project, the bundle eliminates that repetition.

**Choose Ecotone** when you need sagas, event sourcing, or a full CQRS/ES framework. An event-sourced domain with aggregate versioning, long-running processes, and complex read model projections is Ecotone's sweet spot. If your architecture already commits to the CQRS/ES pattern at every layer, Ecotone gives you the infrastructure to support it without building saga orchestration and event stores from scratch.

## Conclusion

There is no universal best choice. Raw Messenger is part of Symfony and costs nothing to adopt. The CQRS bundle adds structure for teams that need typed buses and per-message policies without leaving the Messenger ecosystem. Ecotone provides a complete CQRS/ES framework for projects that need sagas and event sourcing.

Pick the tool that matches your project's complexity. Start simple -- you can always add structure later.

- [somework/cqrs-bundle on GitHub](https://github.com/somework/cqrs)
- [somework/cqrs-bundle documentation](https://somework.github.io/cqrs/)
- [Ecotone Framework](https://docs.ecotone.tech/)
- [Symfony Messenger documentation](https://symfony.com/doc/current/messenger.html)
