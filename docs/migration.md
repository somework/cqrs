# Migrating from Symfony Messenger

This guide walks you through migrating an application that uses raw Symfony
Messenger to `somework/cqrs-bundle`. The bundle builds on top of Messenger --
it does not replace it. Your existing transport configuration, message
serializers, and middleware continue to work.

**Who this is for:** Teams already using `symfony/messenger` for commands,
queries, or events who want typed bus facades, attribute-based handler
registration, and a configurable stamp pipeline.

**What stays the same:** Messenger transports, worker processes, retry
strategies, and serializer configuration remain in `framework.messenger`.

**What changes:** Handler discovery, bus injection, and message-level
configuration move from YAML tags to PHP attributes and the `somework_cqrs`
config key.

## Step 1: Install the bundle

```bash
composer require somework/cqrs-bundle
```

If you use Symfony Flex, the bundle is registered automatically. Otherwise add
it to `config/bundles.php`:

```php
return [
    // ...
    SomeWork\CqrsBundle\CqrsBundle::class => ['all' => true],
];
```

Create a minimal configuration file:

```yaml
# config/packages/somework_cqrs.yaml
somework_cqrs:
    buses:
        command: messenger.bus.commands      # your existing command bus
        query: messenger.bus.default         # or a dedicated query bus
        event: messenger.bus.events          # your existing event bus
```

## Step 2: Add marker interfaces to messages

Add the appropriate marker interface to each message class. This enables the
bundle's typed bus facades and per-message configuration.

=== "Before"

    ```php
    final class CreateTask
    {
        public function __construct(
            public readonly string $name,
        ) {
        }
    }
    ```

=== "After"

    ```php
    use SomeWork\CqrsBundle\Contract\Command;

    final class CreateTask implements Command
    {
        public function __construct(
            public readonly string $name,
        ) {
        }
    }
    ```

Repeat for queries (`implements Query`) and events (`implements Event`). These
are marker interfaces with no methods to implement.

!!! tip
    Marker interfaces are optional since v0.4.0. You can skip this step and rely
    on attribute-only handlers instead. See [Attribute-only handlers](usage.md#attribute-only-handlers).

## Step 3: Replace handler tags with attributes

Remove the `messenger.message_handler` tag from your service definitions and
annotate the handler class instead.

=== "Before"

    ```yaml
    # config/services.yaml
    services:
        App\Handler\CreateTaskHandler:
            tags:
                - { name: messenger.message_handler, bus: messenger.bus.commands }
    ```

    ```php
    class CreateTaskHandler
    {
        public function __invoke(CreateTask $command): void
        {
            // ...
        }
    }
    ```

=== "After"

    ```php
    use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
    use SomeWork\CqrsBundle\Contract\CommandHandler;

    #[AsCommandHandler(command: CreateTask::class)]
    final class CreateTaskHandler implements CommandHandler
    {
        public function __invoke(CreateTask $command): mixed
        {
            // ...
            return null;
        }
    }
    ```

The bundle auto-discovers annotated handlers. No `services.yaml` tag is needed.

The same pattern applies to queries (`#[AsQueryHandler]`) and events
(`#[AsEventHandler]`).

## Step 4: Switch to typed bus facades

Replace generic `MessageBusInterface` injection with the bundle's typed
interfaces. This gives you compile-time type safety and purpose-specific APIs.

=== "Before"

    ```php
    use Symfony\Component\Messenger\MessageBusInterface;

    final class TaskController
    {
        public function __construct(
            private readonly MessageBusInterface $commandBus,
            private readonly MessageBusInterface $queryBus,
        ) {
        }

        public function create(): Response
        {
            $this->commandBus->dispatch(new CreateTask('Write docs'));

            return new Response('', 202);
        }

        public function show(string $id): JsonResponse
        {
            $envelope = $this->queryBus->dispatch(new FindTask($id));
            $result = $envelope->last(HandledStamp::class)?->getResult();

            return new JsonResponse($result);
        }
    }
    ```

=== "After"

    ```php
    use SomeWork\CqrsBundle\Contract\CommandBusInterface;
    use SomeWork\CqrsBundle\Contract\QueryBusInterface;

    final class TaskController
    {
        public function __construct(
            private readonly CommandBusInterface $commandBus,
            private readonly QueryBusInterface $queryBus,
        ) {
        }

        public function create(): Response
        {
            $this->commandBus->dispatch(new CreateTask('Write docs'));

            return new Response('', 202);
        }

        public function show(string $id): JsonResponse
        {
            $result = $this->queryBus->ask(new FindTask($id));

            return new JsonResponse($result);
        }
    }
    ```

Key differences:

- `CommandBusInterface::dispatch()` accepts only `Command` instances
- `QueryBusInterface::ask()` returns the handler result directly -- no need to
  unwrap `HandledStamp`
- `EventBusInterface::dispatch()` accepts only `Event` instances

## Step 5: Configure retry and transport via bundle config

Move per-message retry policies, transport routing, and serializer overrides
from `framework.messenger` to `somework_cqrs`.

=== "Before"

    ```yaml
    # config/packages/messenger.yaml
    framework:
        messenger:
            routing:
                App\Command\SendNotification: async
            transports:
                async:
                    dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                    retry_strategy:
                        max_retries: 3
                        delay: 1000
                        multiplier: 2
    ```

=== "After"

    ```yaml
    # config/packages/somework_cqrs.yaml
    somework_cqrs:
        dispatch_modes:
            command:
                default: sync
                map:
                    App\Command\SendNotification: async

        retry_strategy:
            transports:
                async: command
            jitter: 0.1
            max_delay: 60000

        transport:
            command:
                map:
                    App\Command\SendNotification: async
    ```

Messenger transport DSN and worker configuration remain in
`framework.messenger`. Only the message-level dispatch behavior moves to the
bundle config.

See the [configuration reference](reference.md) for the complete list of
options.

## Step 6: Adopt testing utilities

Replace custom test doubles with the bundle's built-in fakes and assertions.

=== "Before"

    ```php
    final class TaskServiceTest extends TestCase
    {
        public function testCreatesTask(): void
        {
            $dispatched = [];
            $bus = $this->createMock(MessageBusInterface::class);
            $bus->method('dispatch')
                ->willReturnCallback(function ($message) use (&$dispatched) {
                    $dispatched[] = $message;
                    return new Envelope($message);
                });

            $service = new TaskService($bus);
            $service->create('Write docs');

            $this->assertCount(1, $dispatched);
            $this->assertInstanceOf(CreateTask::class, $dispatched[0]);
        }
    }
    ```

=== "After"

    ```php
    use SomeWork\CqrsBundle\Testing\CqrsAssertionsTrait;
    use SomeWork\CqrsBundle\Testing\FakeCommandBus;

    final class TaskServiceTest extends TestCase
    {
        use CqrsAssertionsTrait;

        public function testCreatesTask(): void
        {
            $bus = new FakeCommandBus();

            $service = new TaskService($bus);
            $service->create('Write docs');

            self::assertDispatched($bus, CreateTask::class);
        }
    }
    ```

`assertDispatched()` also supports callback assertions for property checks:

```php
self::assertDispatched(
    $bus,
    CreateTask::class,
    fn (CreateTask $msg) => $msg->name === 'Write docs',
);
```

See the [testing guide](testing.md) for the complete testing API.

## Summary

| Step | What changes | Effort |
|------|-------------|--------|
| 1. Install | Add composer package and bundle config | 5 min |
| 2. Marker interfaces | Add `implements Command/Query/Event` | Low (find & replace) |
| 3. Handler attributes | Replace YAML tags with PHP attributes | Medium (per handler) |
| 4. Typed buses | Swap `MessageBusInterface` for typed interfaces | Medium (per injection point) |
| 5. Bundle config | Move dispatch/retry/transport config | Low (config translation) |
| 6. Test utilities | Replace mocks with fakes and assertions | Low (per test file) |

Steps 2 and 3 can be done incrementally -- the bundle supports both raw
Messenger tags and its own attributes simultaneously during migration.
