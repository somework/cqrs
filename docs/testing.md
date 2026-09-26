# Testing

The bundle ships test helpers in the `SomeWork\CqrsBundle\Testing` namespace:

- **Fake buses**: `FakeCommandBus`, `FakeQueryBus` and `FakeEventBus`. They implement
  `CommandBusInterface`, `QueryBusInterface` and `EventBusInterface` and record every call
  instead of dispatching through Messenger.
- **Assertions**: `CqrsAssertionsTrait` (or the `CqrsTestCase` base class) adds
  `assertDispatched()` and `assertNotDispatched()`. The `DispatchedMessage` PHPUnit
  constraint does the matching behind them.

The real `CommandBus`, `QueryBus` and `EventBus` classes are `final`. Type-hint the
interfaces in your services so a test can pass in a fake.

## Requirements

The assertion helpers (`CqrsAssertionsTrait`, `CqrsTestCase`, `Constraint\DispatchedMessage`)
are built on PHPUnit. The bundle does not require PHPUnit itself, so your application must
install it:

```bash
composer require --dev phpunit/phpunit
```

The trait uses the `#[Before]` attribute, which needs PHPUnit 10 or newer. The bundle's own
test suite runs on PHPUnit 11.5. The fake buses have no PHPUnit dependency.

## Fake buses

| Class | Methods it records | Configuring results | Other methods |
|-------|--------------------|---------------------|---------------|
| `FakeCommandBus` | `dispatch()`, `dispatchSync()`, `dispatchAsync()` | `willReturn(mixed $result)`: the value `dispatchSync()` returns (default `null`); `willReturnFor(string $commandClass, mixed $result)`: the result for one command class; `willThrow(Throwable $exception, ?string $commandClass = null)`: `dispatchSync()` throws it | `getDispatched()`, `reset()` |
| `FakeQueryBus` | `ask()` | `willReturn(mixed $result)`: the default result; `willReturnFor(string $queryClass, mixed $result)`: the result for one query class; `willThrow(Throwable $exception, ?string $queryClass = null)`: `ask()` throws it | `getDispatched()`, `reset()` |
| `FakeEventBus` | `dispatch()`, `dispatchSync()`, `dispatchAsync()` | none | `getDispatched()`, `reset()` |

A fake handles nothing. It does not run the stamp pipeline or resolve dispatch modes, and it
sends nothing to a transport. The dispatch methods return `new Envelope($message, $stamps)`,
built from the stamps you passed.

### What `getDispatched()` returns

`getDispatched()` returns one `SomeWork\CqrsBundle\Testing\RecordedDispatch` per call, in
call order, with the read-only properties `message`, `mode` (a `DispatchMode`, `null` for
queries, which are always synchronous) and `stamps` (the stamps you passed). A message is
recorded before a configured exception is thrown.

The `mode` records the method that was called:

- `dispatchSync()` records `DispatchMode::SYNC`.
- `dispatchAsync()` records `DispatchMode::ASYNC`.
- `dispatch()` records the mode argument you passed, or `DispatchMode::DEFAULT` when you
  passed none. The fake does not resolve `DEFAULT` through `dispatch_modes` or
  `#[Asynchronous]`.

`reset()` clears the records. On `FakeCommandBus` and `FakeQueryBus` it also clears any
results and exceptions set with `willReturn()`, `willReturnFor()` or `willThrow()`.

### FakeCommandBus

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Command\CreateTask;
use App\Application\TaskService;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Testing\CqrsAssertionsTrait;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;

final class TaskServiceTest extends TestCase
{
    use CqrsAssertionsTrait;

    public function test_creating_a_task_dispatches_create_task(): void
    {
        $commandBus = new FakeCommandBus();
        $service = new TaskService($commandBus);

        $service->createTask('task-1', 'Write docs');

        self::assertDispatched($commandBus, CreateTask::class);

        $records = $commandBus->getDispatched();
        self::assertCount(1, $records);
        $message = $records[0]->message;
        self::assertInstanceOf(CreateTask::class, $message);
        self::assertSame('task-1', $message->id);
    }

    public function test_dispatch_sync_returns_the_configured_result(): void
    {
        $commandBus = new FakeCommandBus();
        $commandBus->willReturn('task-42');

        $service = new TaskService($commandBus);

        // TaskService calls $commandBus->dispatchSync(...) and returns the handler result.
        self::assertSame('task-42', $service->createTaskAndReturnId('Write docs'));
    }
}
```

### FakeQueryBus

`willReturnFor()` matches the query's exact class; subclasses do not inherit a result. It
takes precedence over `willReturn()`. When neither is set, `ask()` returns `null`.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Query\FindTask;
use App\Application\Query\ListTasks;
use App\Application\TaskDashboard;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Testing\CqrsAssertionsTrait;
use SomeWork\CqrsBundle\Testing\FakeQueryBus;

final class TaskDashboardTest extends TestCase
{
    use CqrsAssertionsTrait;

    public function test_dashboard_lists_tasks(): void
    {
        $queryBus = new FakeQueryBus();
        $queryBus->willReturn([]);                                     // default for every query
        $queryBus->willReturnFor(ListTasks::class, ['task-1', 'task-2']); // result for ListTasks only

        $dashboard = new TaskDashboard($queryBus);

        self::assertSame(['task-1', 'task-2'], $dashboard->taskIds());
        self::assertDispatched($queryBus, ListTasks::class);
        self::assertNotDispatched($queryBus, FindTask::class);
    }
}
```

### FakeEventBus

The records include the stamps passed to the bus, so a test can check them too:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Event\TaskCreated;
use App\Application\TaskService;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Stamp\IdempotencyStamp;
use SomeWork\CqrsBundle\Testing\CqrsAssertionsTrait;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;
use SomeWork\CqrsBundle\Testing\FakeEventBus;

final class TaskCreatedEventTest extends TestCase
{
    use CqrsAssertionsTrait;

    public function test_task_created_is_published_asynchronously_with_an_idempotency_key(): void
    {
        $eventBus = new FakeEventBus();
        $service = new TaskService(new FakeCommandBus(), $eventBus);

        $service->createTask('task-1', 'Write docs');

        self::assertDispatched(
            $eventBus,
            TaskCreated::class,
            static fn (TaskCreated $event): bool => 'task-1' === $event->taskId,
        );

        $record = $eventBus->getDispatched()[0];
        self::assertSame(DispatchMode::ASYNC, $record->mode);
        self::assertInstanceOf(IdempotencyStamp::class, $record->stamps[0]);
    }
}
```

## Assertions

`CqrsAssertionsTrait` provides these `protected static` assertions:

- `assertDispatched(RecordsBusDispatches $bus, string $messageClass, ?callable $callback = null, string $message = ''): void`
- `assertNotDispatched(RecordsBusDispatches $bus, string $messageClass, ?callable $callback = null, string $message = ''): void`
- `assertStoredInOutbox()` and `assertNotStoredInOutbox()`, with the same parameters: they only
  match dispatches recorded with `DispatchMode::OUTBOX` (see
  [Code that stores messages in the outbox](#code-that-stores-messages-in-the-outbox)).

Their parameters work as follows:

- `$bus` is any object that implements `RecordsBusDispatches`. The three fakes do, and your
  own test doubles can too.
- A record matches when its message is an `instanceof $messageClass`, so subclasses and
  interfaces match as well.
- `$callback` receives the message and returns `true` for a match. `assertDispatched()`
  passes when at least one recorded message matches both the class and the callback.
  `assertNotDispatched()` passes when none does.
- `$message` is the custom failure message. It is the **fourth** parameter, so pass it by
  name when you have no callback. A message string passed third lands in `$callback` and
  causes a `TypeError`.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Command\CreateTask;
use App\Application\Command\DeleteTask;
use SomeWork\CqrsBundle\Testing\CqrsTestCase;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;

final class AssertionExamplesTest extends CqrsTestCase
{
    public function test_examples(): void
    {
        $bus = new FakeCommandBus();
        $bus->dispatch(new CreateTask('task-1', 'Write docs'));

        self::assertDispatched($bus, CreateTask::class);
        self::assertDispatched($bus, CreateTask::class, static fn (CreateTask $c): bool => 'Write docs' === $c->name);
        self::assertDispatched($bus, CreateTask::class, message: 'CreateTask must be dispatched after the form is submitted');
        self::assertNotDispatched($bus, DeleteTask::class);
    }
}
```

On failure, the message names the classes that were actually dispatched
(`Actually dispatched: App\Application\Command\CreateTask`), or says
`No messages were dispatched.`

### CqrsTestCase or CqrsAssertionsTrait

`CqrsTestCase` is an abstract `PHPUnit\Framework\TestCase` that uses the trait. Extend it
for plain unit tests. When your test already extends `KernelTestCase`, `WebTestCase` or
another base class, use the trait instead.

The trait also adds `resetCqrsState()`, which is marked `#[Before]`. It clears the bundle's
internal message-type resolution cache before each test. That cache is keyed by service
locator, so separate containers do not share entries. The reset is only a safety net, and
you never need to call it yourself.

### Using the constraint directly

`Constraint\DispatchedMessage` takes `(string $expectedClass, ?callable $callback = null)`.
You can combine it with PHPUnit's logical constraints:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Command\CreateTask;
use App\Application\Command\DeleteTask;
use PHPUnit\Framework\Constraint\LogicalNot;
use PHPUnit\Framework\Constraint\LogicalOr;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Testing\Constraint\DispatchedMessage;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;

final class ConstraintExamplesTest extends TestCase
{
    public function test_constraint(): void
    {
        $bus = new FakeCommandBus();
        $bus->dispatch(new CreateTask('task-1', 'Write docs'));

        self::assertThat($bus, new DispatchedMessage(CreateTask::class));
        self::assertThat($bus, new LogicalNot(new DispatchedMessage(DeleteTask::class)));
        self::assertThat($bus, LogicalOr::fromConstraints(
            new DispatchedMessage(CreateTask::class),
            new DispatchedMessage(DeleteTask::class),
        ));
    }
}
```

## Testing handlers directly

Handlers are services with a typed `__invoke()` method. To test one, create it with real or
in-memory dependencies and call it with a message. No bus is involved:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Command\CreateTask;
use App\Application\Command\CreateTaskHandler;
use App\Application\Query\FindTask;
use App\Application\Query\FindTaskHandler;
use App\Tests\Double\InMemoryTaskRepository;
use PHPUnit\Framework\TestCase;

final class TaskHandlersTest extends TestCase
{
    public function test_create_then_find(): void
    {
        $repository = new InMemoryTaskRepository();

        (new CreateTaskHandler($repository))(new CreateTask('task-1', 'Write docs'));
        $task = (new FindTaskHandler($repository))(new FindTask('task-1'));

        self::assertSame('Write docs', $task->name);
    }
}
```

When you call an `EnvelopeAware` handler directly and it reads `$this->getEnvelope()`,
first pass an envelope with `$handler->setEnvelope(new Envelope($message))`.

## Swapping the buses for fakes in the test container

Kernel and web tests use the real services. To make everything that depends on
`CommandBusInterface`, `QueryBusInterface` or `EventBusInterface` receive a fake, redefine
the interface aliases for the `test` environment. Use either `config/services_test.yaml` or
a `when@test` block in `config/services.yaml`:

```yaml
# config/services.yaml
when@test:
    services:
        SomeWork\CqrsBundle\Testing\FakeCommandBus:
            public: true
        SomeWork\CqrsBundle\Testing\FakeQueryBus:
            public: true
        SomeWork\CqrsBundle\Testing\FakeEventBus:
            public: true

        SomeWork\CqrsBundle\Contract\CommandBusInterface:
            alias: SomeWork\CqrsBundle\Testing\FakeCommandBus
            public: true
        SomeWork\CqrsBundle\Contract\QueryBusInterface:
            alias: SomeWork\CqrsBundle\Testing\FakeQueryBus
            public: true
        SomeWork\CqrsBundle\Contract\EventBusInterface:
            alias: SomeWork\CqrsBundle\Testing\FakeEventBus
            public: true
```

Application configuration overrides the aliases the bundle registers. Every service that
type-hints an interface then gets the fake. Fetch the same instance from the test container
to configure it and assert on it:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Command\CreateTask;
use App\Application\Query\FindTask;
use SomeWork\CqrsBundle\Testing\CqrsAssertionsTrait;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;
use SomeWork\CqrsBundle\Testing\FakeQueryBus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TaskControllerTest extends WebTestCase
{
    use CqrsAssertionsTrait;

    public function test_post_dispatches_create_task(): void
    {
        $client = static::createClient();

        $client->request('POST', '/tasks', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"Write docs"}');

        self::assertResponseStatusCodeSame(201);
        self::assertDispatched(
            static::getContainer()->get(FakeCommandBus::class),
            CreateTask::class,
            static fn (CreateTask $command): bool => 'Write docs' === $command->name,
        );
    }

    public function test_get_renders_the_query_result(): void
    {
        $client = static::createClient();
        static::getContainer()->get(FakeQueryBus::class)->willReturnFor(FindTask::class, ['id' => 'task-1', 'name' => 'Write docs']);

        $client->request('GET', '/tasks/task-1');

        self::assertResponseIsSuccessful();
    }
}
```

The fakes are ordinary shared services, so each freshly booted kernel gets new, empty
instances. The test client reboots the kernel before each request after the first one, so
a fake configured before the second request would be lost: call `$client->disableReboot()`
when a test sends several requests, and configure the fakes after `createClient()`. Only the interface aliases change. The concrete `SomeWork\CqrsBundle\Bus\CommandBus`,
`QueryBus` and `EventBus` services stay registered and public, and services that type-hint
those classes still get the real buses.

## Kernel tests through the real buses

To test the whole flow (stamp pipeline, Messenger middleware, handlers), boot the kernel and
fetch the buses from the container:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Command\CreateTask;
use App\Application\Query\FindTask;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\QueryBus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TaskFlowTest extends KernelTestCase
{
    public function test_a_created_task_can_be_found(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        // The concrete services are the real buses even when the interfaces point to fakes.
        $commandBus = $container->get(CommandBus::class);
        $queryBus = $container->get(QueryBus::class);

        $commandBus->dispatchSync(new CreateTask('task-1', 'Write docs'));
        $task = $queryBus->ask(new FindTask('task-1'));

        self::assertSame('Write docs', $task->name);
    }
}
```

If you did not swap in fakes, fetching `CommandBusInterface::class` and `QueryBusInterface::class`
works as well, because both aliases are public.

`dispatchSync()` and `ask()` return the handler result. When the single handler throws,
they rethrow its exception unwrapped, so `expectException(TaskNotFound::class)` works
directly. Messages that the dispatch mode or routing sends to an asynchronous transport are
not handled during the test. In the test environment, point those transports at Messenger's
in-memory transport and assert on what was sent:

```yaml
# config/packages/messenger.yaml
when@test:
    framework:
        messenger:
            transports:
                # serialize=true encodes and decodes each message, as a real transport does,
                # so a message that cannot be serialized fails the test instead of production.
                async: 'in-memory://?serialize=true'
```

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Event\TaskCreated;
use SomeWork\CqrsBundle\Bus\EventBus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AsyncEventTest extends KernelTestCase
{
    public function test_task_created_goes_to_the_async_transport(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        // TaskCreated is routed to the "async" transport (Messenger routing or somework_cqrs.transports).
        $container->get(EventBus::class)->dispatchAsync(new TaskCreated('task-1', 'Write docs'));

        $sent = $container->get('messenger.transport.async')->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(TaskCreated::class, $sent[0]->getMessage());
    }
}
```

To assert on what went through the real buses without a transport, use Messenger's
profiler integration: with the profiler enabled in the `test` environment
(`framework.profiler.enabled: true`) each Messenger bus is decorated by a
`TraceableMessageBus`. While collecting (`framework.profiler.collect: true`, or
`$client->enableProfiler()` before a request), `getDispatchedMessages()` on the bus service
(`messenger.default_bus`, or an id you configured under `somework_cqrs.buses`, such as
`event.bus`) lists each dispatched message with its stamps.

To handle what was sent to an in-memory transport, run a worker in the test, for example
the `messenger:consume async --limit=1 --time-limit=5` command through `CommandTester`
(the time limit makes an empty transport fail the test instead of hanging it).

`dispatchAsync()` requires an async bus (`somework_cqrs.buses.event_async` for events,
`command_async` for commands). Without one, the bus throws
`AsyncBusNotConfiguredException`.

## Code that stores messages in the outbox

Code that stores messages through the buses (`dispatch($message, DispatchMode::OUTBOX)`) is tested
with the fake buses, which record the mode:

```php
$eventBus = new FakeEventBus();
(new PlaceOrderHandler($connection, $eventBus))(new PlaceOrder('order-1'));

self::assertStoredInOutbox($eventBus, OrderPlaced::class, static fn (OrderPlaced $event): bool => 'order-1' === $event->orderId);
```

A fake bus does not resolve the configuration: a `dispatch()` with the default mode that
`#[Outbox]` or `dispatch_modes` sends to the outbox is recorded as `DispatchMode::DEFAULT`, so
check it with `assertDispatched()`, or test the resolution in a kernel test as below.

`OutboxWriter` has no fake: test the code that uses it against a real outbox table. In the
`test` environment, point the outbox at a connection of its own (an SQLite file or in-memory
database is enough) and create the table before the code under test opens its transaction (the
automatic setup never runs inside one). Then read the stored rows through the `OutboxStorage`
service, or relay them to an in-memory transport:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Command\PlaceOrder;
use App\Domain\Event\OrderPlaced;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxSchema;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PlaceOrderOutboxTest extends KernelTestCase
{
    public function test_the_order_placed_event_is_stored_then_relayed(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $container->get(OutboxSchema::class)->setup();

        $container->get(CommandBus::class)->dispatchSync(new PlaceOrder('order-1'));

        // Each row records the message class in its "type" header.
        $rows = $container->get(OutboxStorage::class)->fetchUnpublished(10);
        self::assertCount(1, $rows);
        self::assertSame(OrderPlaced::class, json_decode($rows[0]->headers, true)['type']);

        $relay = new CommandTester((new Application(self::$kernel))->find('somework:cqrs:outbox:relay'));
        self::assertSame(0, $relay->execute([]));
        self::assertCount(1, $container->get('messenger.transport.async')->getSent());
    }
}
```

## Tips

- **Type-hint the interfaces** (`CommandBusInterface`, `QueryBusInterface`,
  `EventBusInterface`) in your services. Unit tests can then pass a fake, and the test
  container can swap the aliases.
- **Unit-test handlers without buses.** Call `__invoke()` directly. Use the fakes to test the
  code that dispatches.
- **Check message properties** with the `assertDispatched()` callback, or read
  `getDispatched()[n]->message`.
- **Check the dispatch mode** through the `mode` entry of a record, not by relying on
  `dispatch_modes` configuration: the fakes record the mode the caller passed.
