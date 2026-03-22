# Testing with somework/cqrs-bundle

This bundle provides a set of testing utilities that let you verify bus dispatching behavior
without booting the Symfony container or wiring Messenger transports. The utilities are
purpose-built for the three bus types (command, query, event) and integrate directly with
PHPUnit's assertion API.

The real bus classes (`CommandBus`, `QueryBus`, `EventBus`) are `final` and depend on
Messenger infrastructure, which makes mocking them impractical. Instead, the bundle ships
**FakeBus** test doubles that record every dispatch call and let you inspect what happened
after the fact. Combined with `CqrsAssertionsTrait`, you get clean, readable test
assertions without manual array inspection.

To get started, either extend `CqrsTestCase` (for simple unit tests) or add
`CqrsAssertionsTrait` to any existing test class. Both approaches give you
`assertDispatched()` and `assertNotDispatched()` helpers, plus automatic
`MessageTypeLocator` cache cleanup between tests.

## FakeBus Usage

### FakeCommandBus

Use `FakeCommandBus` to test services that dispatch commands. It records every call to
`dispatch()`, `dispatchSync()`, and `dispatchAsync()`.

```php
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Testing\CqrsAssertionsTrait;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;

final class OrderServiceTest extends TestCase
{
    use CqrsAssertionsTrait;

    public function test_placing_order_dispatches_command(): void
    {
        $commandBus = new FakeCommandBus();
        $service = new OrderService($commandBus);

        $service->placeOrder('order-123', 'Widget', 3);

        // Simple class-level assertion
        self::assertDispatched($commandBus, PlaceOrderCommand::class);

        // Inspect specific property values
        $dispatched = $commandBus->getDispatched();
        self::assertCount(1, $dispatched);
        self::assertSame('order-123', $dispatched[0]['message']->orderId);
        self::assertSame(3, $dispatched[0]['message']->quantity);
    }

    public function test_dispatch_sync_returns_configured_result(): void
    {
        $commandBus = new FakeCommandBus();
        $commandBus->willReturn('generated-id-456');

        $service = new OrderService($commandBus);
        $result = $service->createOrderSync('Widget', 1);

        self::assertSame('generated-id-456', $result);
    }
}
```

### FakeQueryBus

Use `FakeQueryBus` to test services that ask queries. Configure return values with
`willReturn()` for a default result, or `willReturnFor()` for per-query-class results.

```php
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Testing\CqrsAssertionsTrait;
use SomeWork\CqrsBundle\Testing\FakeQueryBus;

final class TaskDashboardTest extends TestCase
{
    use CqrsAssertionsTrait;

    public function test_dashboard_loads_tasks(): void
    {
        $queryBus = new FakeQueryBus();
        $queryBus->willReturn([
            ['id' => 'task-1', 'name' => 'Review PR'],
            ['id' => 'task-2', 'name' => 'Deploy staging'],
        ]);

        $dashboard = new TaskDashboard($queryBus);
        $result = $dashboard->load();

        self::assertDispatched($queryBus, ListTasksQuery::class);
        self::assertCount(2, $result);
    }

    public function test_per_query_class_results(): void
    {
        $queryBus = new FakeQueryBus();
        $queryBus->willReturnFor(FindTaskQuery::class, ['id' => 'task-1', 'name' => 'Review PR']);
        $queryBus->willReturnFor(ListTasksQuery::class, []);

        $dashboard = new TaskDashboard($queryBus);
        $task = $dashboard->findTask('task-1');

        self::assertSame('task-1', $task['id']);
        self::assertDispatched($queryBus, FindTaskQuery::class);
    }
}
```

### FakeEventBus

Use `FakeEventBus` to test services that dispatch events. It records `dispatch()`,
`dispatchSync()`, and `dispatchAsync()` calls with their dispatch mode.

```php
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Testing\CqrsAssertionsTrait;
use SomeWork\CqrsBundle\Testing\FakeEventBus;

final class UserRegistrationServiceTest extends TestCase
{
    use CqrsAssertionsTrait;

    public function test_registration_dispatches_event(): void
    {
        $eventBus = new FakeEventBus();
        $service = new UserRegistrationService($eventBus);

        $service->register('user@example.com', 'Jane Doe');

        self::assertDispatched($eventBus, UserRegisteredEvent::class);
    }

    public function test_duplicate_registration_does_not_dispatch(): void
    {
        $eventBus = new FakeEventBus();
        $service = new UserRegistrationService($eventBus);

        $service->registerIdempotent('user@example.com', 'Jane Doe');
        $service->registerIdempotent('user@example.com', 'Jane Doe');

        // Verify only one event was dispatched (idempotency)
        $dispatched = $eventBus->getDispatched();
        self::assertCount(1, $dispatched);
    }
}
```

### Resetting Between Tests

If you use `CqrsAssertionsTrait` or extend `CqrsTestCase`, `MessageTypeLocator` is reset
automatically before each test via a `#[Before]` hook. FakeBus instances are typically
created per test method, so they start empty. If you share a FakeBus across tests (e.g.,
via `setUp()`), call `reset()` explicitly:

```php
protected function setUp(): void
{
    parent::setUp();
    $this->commandBus = new FakeCommandBus();
    // Or if reusing: $this->commandBus->reset();
}
```

## Handler Isolation Testing

Handlers are plain PHP classes with an `__invoke()` method. Test them directly without
any bus infrastructure -- inject real or fake dependencies and call `__invoke()` with
a message instance.

### Command Handler

```php
use PHPUnit\Framework\TestCase;

final class CreateTaskHandlerTest extends TestCase
{
    public function test_creates_task_in_repository(): void
    {
        $repository = new InMemoryTaskRepository();
        $handler = new CreateTaskHandler($repository);

        $handler(new CreateTaskCommand(
            id: 'task-123',
            name: 'Write documentation',
        ));

        $task = $repository->findById('task-123');
        self::assertNotNull($task);
        self::assertSame('Write documentation', $task->name);
    }
}
```

### Query Handler

```php
use PHPUnit\Framework\TestCase;

final class FindTaskHandlerTest extends TestCase
{
    public function test_returns_task_when_found(): void
    {
        $repository = new InMemoryTaskRepository();
        $repository->save(new Task('task-123', 'Review PR'));

        $handler = new FindTaskHandler($repository);
        $result = $handler(new FindTaskQuery(id: 'task-123'));

        self::assertSame('task-123', $result->id);
        self::assertSame('Review PR', $result->name);
    }

    public function test_returns_null_when_not_found(): void
    {
        $repository = new InMemoryTaskRepository();
        $handler = new FindTaskHandler($repository);

        $result = $handler(new FindTaskQuery(id: 'nonexistent'));

        self::assertNull($result);
    }
}
```

Handlers are tested WITHOUT buses. Inject real or fake dependencies (repositories,
services), not the bus itself. The bus is a dispatch mechanism, not a handler dependency.

## Async Dispatch Testing

FakeBus test doubles record the `DispatchMode` for each dispatch call. Use this to
verify that your service dispatches messages with the expected mode.

### Verifying Async Command Dispatch

```php
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Testing\CqrsAssertionsTrait;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;

final class BulkImportServiceTest extends TestCase
{
    use CqrsAssertionsTrait;

    public function test_bulk_import_dispatches_async(): void
    {
        $commandBus = new FakeCommandBus();
        $service = new BulkImportService($commandBus);

        $service->importBatch(['item-1', 'item-2', 'item-3']);

        $dispatched = $commandBus->getDispatched();
        self::assertCount(3, $dispatched);

        foreach ($dispatched as $record) {
            self::assertSame(DispatchMode::ASYNC, $record['mode']);
        }
    }
}
```

### Verifying Event Dispatch Mode

```php
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Testing\FakeEventBus;

final class NotificationServiceTest extends TestCase
{
    public function test_critical_events_dispatched_sync(): void
    {
        $eventBus = new FakeEventBus();
        $service = new NotificationService($eventBus);

        $service->notifyCritical('System overload detected');

        $dispatched = $eventBus->getDispatched();
        self::assertCount(1, $dispatched);
        self::assertSame(DispatchMode::SYNC, $dispatched[0]['mode']);
    }
}
```

### Filtering Dispatched Messages by Mode

When a service dispatches multiple messages with different modes, filter the
`getDispatched()` array:

```php
$dispatched = $commandBus->getDispatched();

$asyncDispatches = array_filter(
    $dispatched,
    static fn (array $record): bool => $record['mode'] === DispatchMode::ASYNC,
);

$syncDispatches = array_filter(
    $dispatched,
    static fn (array $record): bool => $record['mode'] === DispatchMode::SYNC,
);

self::assertCount(2, $asyncDispatches);
self::assertCount(1, $syncDispatches);
```

## CqrsTestCase vs CqrsAssertionsTrait

### Extend CqrsTestCase

Use `CqrsTestCase` when your test class does not already extend another base class.
It extends `PHPUnit\Framework\TestCase` and includes `CqrsAssertionsTrait`:

```php
use SomeWork\CqrsBundle\Testing\CqrsTestCase;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;

final class OrderServiceTest extends CqrsTestCase
{
    public function test_order_dispatched(): void
    {
        $bus = new FakeCommandBus();
        // ... test logic ...
        self::assertDispatched($bus, PlaceOrderCommand::class);
    }
}
```

### Use CqrsAssertionsTrait Directly

Use the trait when you already extend `KernelTestCase`, `WebTestCase`, or any other
base class:

```php
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use SomeWork\CqrsBundle\Testing\CqrsAssertionsTrait;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;

final class OrderIntegrationTest extends KernelTestCase
{
    use CqrsAssertionsTrait;

    public function test_order_flow(): void
    {
        self::bootKernel();
        $bus = new FakeCommandBus();
        // ... integration test logic ...
        self::assertDispatched($bus, PlaceOrderCommand::class);
    }
}
```

### MessageTypeLocator Auto-Reset

Both `CqrsTestCase` and `CqrsAssertionsTrait` include a `#[Before]` hook that calls
`MessageTypeLocator::reset()` before each test method. This clears the static `WeakMap`
cache used for message-to-service resolution, preventing state leakage between tests.

Without this reset, a test that boots the kernel and resolves message types could
pollute the cache for subsequent tests that use a different container configuration.

## PHPUnit Constraint API

The `DispatchedMessage` constraint can be used directly with `assertThat()` for
advanced assertion composition.

### Direct Constraint Usage

```php
use SomeWork\CqrsBundle\Testing\Constraint\DispatchedMessage;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;

$bus = new FakeCommandBus();
$bus->dispatch(new CreateTaskCommand('task-1', 'Review PR'));

// Direct assertThat usage
self::assertThat($bus, new DispatchedMessage(CreateTaskCommand::class));
```

### Composing with LogicalNot

```php
use PHPUnit\Framework\Constraint\LogicalNot;
use SomeWork\CqrsBundle\Testing\Constraint\DispatchedMessage;

// Assert message was NOT dispatched
self::assertThat(
    $bus,
    new LogicalNot(new DispatchedMessage(DeleteTaskCommand::class)),
);
```

### Custom Assertion Messages

Both `assertDispatched()` and `assertNotDispatched()` accept an optional message
parameter for clearer failure output:

```php
self::assertDispatched(
    $commandBus,
    PlaceOrderCommand::class,
    'Expected order command after payment confirmation',
);
```

When a dispatch assertion fails, the constraint provides helpful context including
which message classes were actually dispatched (or "No messages were dispatched" if
the bus is empty).

## Tips

- **Always use CqrsAssertionsTrait or CqrsTestCase** to get automatic
  `MessageTypeLocator` cleanup between tests. Without it, static cache from one test
  can affect another.

- **Do not mock final bus classes.** Use `FakeCommandBus`, `FakeQueryBus`, and
  `FakeEventBus` instead. They implement the same method signatures and provide
  introspection via `getDispatched()`.

- **Test handlers in isolation, test dispatching in integration.** Handlers are pure
  logic -- test them by calling `__invoke()` directly with real or fake dependencies.
  Use FakeBus to verify that your services dispatch the right messages.

- **Inspect `getDispatched()` for property values.** The `assertDispatched()` helper
  only checks the message class. For property-level assertions, access the dispatch
  records directly:

  ```php
  $dispatched = $bus->getDispatched();
  self::assertSame('expected-id', $dispatched[0]['message']->id);
  ```

- **Use `willReturn()` and `willReturnFor()` on FakeQueryBus** to configure return
  values. `willReturn()` sets a default for all queries; `willReturnFor()` sets a
  result for a specific query class.
