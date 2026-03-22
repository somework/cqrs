---
paths:
  - tests/**
---

# Test Conventions

## Test Type Selection

**Unit tests** (extend `TestCase`) — for testing a single class in isolation with mocked dependencies. Used for buses, resolvers, deciders, stamps, DI extension, compiler passes. No kernel boot overhead.

**Functional tests** (extend `KernelTestCase`) — for verifying service wiring and full dispatch flow through the container. Override `getKernelClass()` to return `TestKernel::class`. Boot the kernel in `setUp()` and reset `TaskRecorder`:

```php
protected function setUp(): void
{
    parent::setUp();
    self::bootKernel();
    static::getContainer()->get(TaskRecorder::class)->reset();
}
```

## File Organization

Test files mirror `src/` structure: `tests/Bus/CommandBusTest.php` tests `src/Bus/CommandBus.php`. Fixtures (stub messages, handlers, kernels, services) live in `tests/Fixture/` with sub-directories by type — reuse these rather than creating new stubs per test.

## Mock Patterns

Use `$this->createMock()` when verifying method calls with `expects()`. Use anonymous classes implementing the interface for simple stateless stubs.

For complex argument assertions (stamp arrays), use the `self::callback()` pattern inside `->with()`:
```php
->with($message, self::callback(static function (array $stamps): bool {
    self::assertCount(2, $stamps);
    self::assertInstanceOf(RetryStamp::class, $stamps[0]);
    return true;
}))
```

## Helper Factory Methods

Create private factory methods with nullable parameters and `??=` defaults to reduce boilerplate when the same dependencies appear across many tests. See `createCommandStampsDecider()` in `CommandBusTest` for the pattern.

## Attributes

Use PHPUnit 10 attributes, not annotations:
- `#[CoversClass(ClassName::class)]` on the test class
- `#[DataProvider('providerMethodName')]` on test methods — provider methods must be `public static`

## Immutability Verification

When testing immutable objects with `with*()` methods, assert both that a new instance is returned (`assertNotSame`) and that the original is unchanged. See `MessageMetadataStampTest` for the pattern.
