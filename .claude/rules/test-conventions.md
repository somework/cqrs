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

## Kernel Fixtures

Test kernels live in `tests/Fixture/Kernel/`, register a `NullLogger` as `logger` (keeps the output clean) and cache in `var/cache/<kernel>/` inside the project. `AsyncTransportTestKernel` uses a serializing `in-memory://` transport and `messenger:consume` for real async round trips; prefer it over asserting on the async bus handling messages inline.

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

Use PHPUnit attributes, not annotations:
- Every test class declares its coverage target: `#[CoversClass(ClassName::class)]` for classes, `#[CoversTrait]` for traits, `#[CoversNothing]` for kernel tests and interface contracts (`CoversClass` on an interface is a PHPUnit warning, and CI fails on warnings)
- `#[DataProvider('providerMethodName')]` on test methods — provider methods must be `public static`
- Name test methods `test_snake_case_description`; do not use `#[Test]`
- Tests that depend on optional features of newer dependencies (e.g. `DeduplicateStamp` from Messenger 7.3) are guarded with `#[RequiresMethod]`, because CI also runs with the lowest supported versions (Symfony 7.2, DBAL 4.0)
- Never write `self::assertTrue(true)`: assert the observable outcome, or use `$this->expectNotToPerformAssertions()` when "does not throw" is the behaviour

## Immutability Verification

When testing immutable objects with `with*()` methods, assert both that a new instance is returned (`assertNotSame`) and that the original is unchanged. See `MessageMetadataStampTest` for the pattern.
