# Migrating from Symfony Messenger

This guide walks you through migrating an application that uses plain Symfony
Messenger to `somework/cqrs-bundle`. The bundle builds on top of Messenger --
it does not replace it. Your existing transport configuration, message
serializers, and middleware continue to work.

**Who this is for:** Teams already using `symfony/messenger` for commands,
queries, or events who want typed bus facades, attribute-based handler
registration, and a configurable stamp pipeline.

**What stays the same:** Messenger buses, middleware, transports, worker
processes (`messenger:consume`), transport retry strategies, and serializer
configuration remain in `framework.messenger`.

**What changes:** Handler discovery, bus injection, and message-level
configuration move from Messenger tags and `framework.messenger.routing` to the
bundle's PHP attributes and the `somework_cqrs` config key.

!!! note "Upgrading the bundle itself"
    This page covers the move from plain Messenger. For changes between
    versions of this bundle, see
    [UPGRADE.md](https://github.com/somework/cqrs/blob/main/UPGRADE.md).

The examples assume an application with three Messenger buses, `command.bus`,
`query.bus`, and `event.bus`. If your application only uses Messenger's default
bus, you can skip the bus configuration: every CQRS bus then uses the default
bus.

## Step 1: Install the bundle

```bash
composer require somework/cqrs-bundle
```

If you use Symfony Flex, the bundle is registered automatically. Otherwise add
it to `config/bundles.php`:

```php
return [
    // ...
    SomeWork\CqrsBundle\SomeWorkCqrsBundle::class => ['all' => true],
];
```

Point the bundle at your existing buses:

```yaml
# config/packages/somework_cqrs.yaml
somework_cqrs:
    buses:
        command: command.bus
        query: query.bus
        event: event.bus
```

The values are Messenger bus service ids, i.e. the names under
`framework.messenger.buses`.

## Step 2: Add marker interfaces to messages

Add the appropriate marker interface to each message class. The typed buses
only accept messages that implement `Command`, `Query`, or `Event`, and the
per-message configuration of the bundle relies on them.

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
are marker interfaces with no methods to implement. Messages that implement
them can still be dispatched through a plain `MessageBusInterface`, so existing
callers keep working while you migrate.

## Step 3: Replace handler tags with attributes

Replace Messenger's `#[AsMessageHandler]` attribute or the
`messenger.message_handler` tag with the bundle's attribute for the message
type.

=== "Before"

    ```yaml
    # config/services.yaml
    services:
        App\Handler\CreateTaskHandler:
            tags:
                - { name: messenger.message_handler, bus: command.bus }
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

    #[AsCommandHandler(command: CreateTask::class)]
    final class CreateTaskHandler
    {
        public function __invoke(CreateTask $command): mixed
        {
            // ...
            return null;
        }
    }
    ```

The bundle discovers annotated handlers through autoconfiguration, so no
`services.yaml` tag is needed. Without a `bus` argument, a command handler is
registered on `buses.command` and, once you configure one, on
`buses.command_async`; pass `bus: 'my.bus'` to register it on one specific bus
instead. Messenger registers handlers without a bus on every bus, so after the
migration each handler is only reachable from the buses of its type.

Keep the typed first parameter of `__invoke()`. The return type is free:
`void` works for commands, but a returned value is what `dispatchSync()` gives
back to the caller.

The same pattern applies to queries (`#[AsQueryHandler]`) and events
(`#[AsEventHandler]`). If you prefer interfaces, implementing `CommandHandler`,
`QueryHandler`, or `EventHandler` instead of adding the attribute works as well
(see [Interface autoconfiguration](usage.md#interface-autoconfiguration)).

Commands and queries must have exactly one handler per bus: a second handler
for the same command or query on the same bus makes the container compilation
fail. Remove the old tag or `#[AsMessageHandler]` when you add the bundle's
attribute rather than keeping both.

## Step 4: Switch to typed bus facades

Replace generic `MessageBusInterface` injection with the bundle's interfaces.
The interfaces are autowired to the bundle's buses.

=== "Before"

    ```php
    use Symfony\Component\HttpFoundation\JsonResponse;
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\Messenger\MessageBusInterface;
    use Symfony\Component\Messenger\Stamp\HandledStamp;

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
    use Symfony\Component\HttpFoundation\JsonResponse;
    use Symfony\Component\HttpFoundation\Response;

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

- `CommandBusInterface::dispatch()` accepts only `Command` instances and returns
  the envelope. `dispatchSync()` returns the handler result, which replaces
  Messenger's `HandleTrait::handle()` for commands.
- `QueryBusInterface::ask()` returns the handler result directly -- no need to
  unwrap `HandledStamp`.
- `EventBusInterface::dispatch()` accepts only `Event` instances; an event
  without handlers does not throw.
- `dispatchSync()` and `ask()` rethrow the exception of a failing handler
  unwrapped instead of Messenger's `HandlerFailedException`, and throw the
  bundle's own exceptions when there is no single result (see
  [Exceptions from dispatchSync() and ask()](usage.md#exceptions-from-dispatchsync-and-ask)).
  Review `catch` blocks that expect `HandlerFailedException` or the
  `LogicException` of `HandleTrait`.
- Stamps are passed as trailing arguments:
  `$commandBus->dispatch($command, DispatchMode::DEFAULT, new DelayStamp(5000))`.

## Step 5: Move async routing to the bundle config

With plain Messenger, a message is made asynchronous by routing its class to a
transport. That routing applies to every bus, so every dispatch of the message
is sent to the transport. The bundle instead dispatches asynchronous messages on
a dedicated asynchronous bus and adds the transport name to them, so the same
message can still be handled synchronously with `dispatchSync()`.

=== "Before"

    ```yaml
    # config/packages/messenger.yaml
    framework:
        messenger:
            default_bus: command.bus
            buses:
                command.bus: ~
                query.bus: ~
                event.bus: ~
            transports:
                async:
                    dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                    retry_strategy:
                        max_retries: 3
                        delay: 1000
                        multiplier: 2
            routing:
                App\Command\SendNotification: async
    ```

=== "After"

    ```yaml
    # config/packages/messenger.yaml
    framework:
        messenger:
            default_bus: command.bus
            buses:
                command.bus: ~
                command.async_bus: ~
                query.bus: ~
                event.bus: ~
            transports:
                async:
                    dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                    retry_strategy:
                        max_retries: 3
                        delay: 1000
                        multiplier: 2
    ```

    ```yaml
    # config/packages/somework_cqrs.yaml
    somework_cqrs:
        buses:
            command: command.bus
            command_async: command.async_bus
            query: query.bus
            event: event.bus
        dispatch_modes:
            command:
                default: sync
                map:
                    App\Command\SendNotification: async
        transports:
            command_async:
                default: [async]
    ```

What the "After" configuration does:

- `buses.command_async` declares the asynchronous command bus. Command handlers
  without an explicit bus are registered on it automatically, so
  `bin/console messenger:consume async` handles the messages as before.
- `dispatch_modes.command.map` makes `SendNotification` asynchronous when it is
  dispatched with `dispatch()`. Marking the class with `#[Asynchronous]` has
  the same effect; `dispatchAsync()` makes a single call asynchronous.
- `transports.command_async.default` adds a `TransportNamesStamp` for the
  `async` transport to every asynchronous command, which replaces the
  `routing` entry.

The transport and its `retry_strategy` stay in `framework.messenger`. To
configure retries per message class instead, map `RetryPolicy` services under
`retry_policies` and let `retry_strategy.transports` apply them to the
transport; messages without such a policy keep the transport's own strategy.
See [Retry Policies](retry.md).

You can also keep existing `routing` entries while you migrate. `dispatch()`
still sends those messages to the transport, but `dispatchSync()` then throws
`MessageSentToTransportException`, because the message was sent instead of
handled.

See the [configuration reference](reference.md) for the complete list of
options.

## Step 6: Adopt testing utilities

Replace custom test doubles with the bundle's fake buses and assertions. The
service under test now depends on `CommandBusInterface`, which
`FakeCommandBus` implements.

=== "Before"

    ```php
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Messenger\Envelope;
    use Symfony\Component\Messenger\MessageBusInterface;

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
    use PHPUnit\Framework\TestCase;
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
    fn (CreateTask $msg): bool => 'Write docs' === $msg->name,
);
```

See the [testing guide](testing.md) for the complete testing API.

## Summary

| Step | What changes |
|------|-------------|
| 1. Install | Add the package and point `somework_cqrs.buses` at your buses |
| 2. Marker interfaces | Add `implements Command`, `Query`, or `Event` to messages |
| 3. Handler attributes | Replace Messenger tags with `#[AsCommandHandler]`, `#[AsQueryHandler]`, `#[AsEventHandler]` |
| 4. Typed buses | Swap `MessageBusInterface` for the bundle's bus interfaces |
| 5. Async routing | Declare async buses and move `routing` entries to `dispatch_modes` and `transports` |
| 6. Test utilities | Replace mocks with fake buses and assertions |

Steps 2 and 3 can be done incrementally -- the bundle supports both Messenger
tags and its own attributes simultaneously during migration.
