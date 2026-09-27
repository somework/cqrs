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

`DoctrineBundleTestKernel` runs the outbox with the real DoctrineBundle (`#[Group('doctrine-bundle')]`): it checks that the outbox store skips every transaction middleware DoctrineBundle defines, whatever its id. `BrokerTransportTestKernel` sends to the message broker of `CQRS_TEST_TRANSPORT_DSN` (`#[Group('transport')]`, skipped without it); CI runs that group once per Messenger bridge (RabbitMQ, Redis, SQS on LocalStack), with the bridge installed as a dev package. Give each message of such a test an id of its own, so messages a failed run left in the queue do not fail the next one.

## Database Tests

Tests that touch the outbox database get their connection from `TestDatabase::connect()` (in-memory SQLite, or the database of `CQRS_TEST_DATABASE_URL`, whose tables it drops first) and carry `#[Group('database')]`, which CI runs on PostgreSQL, MySQL and MariaDB. Use UUIDs as outbox ids (PostgreSQL stores them in a `uuid` column) and build legacy tables with the schema API (`TestDatabase::createTableOfVersion04()`), not with SQLite-only DDL.

## File Organization

Test files mirror `src/` structure: `tests/Bus/CommandBusTest.php` tests `src/Bus/CommandBus.php`. Fixtures (stub messages, handlers, kernels, services) live in `tests/Fixture/` with sub-directories by type — reuse these rather than creating new stubs per test.

## Mock Patterns

Use `$this->createMock()` only when verifying method calls with `expects()`. A test double whose calls are not verified is a stub: `self::createStub()` (configure it with `method()`), or an anonymous class implementing the interface. PHPUnit 12.5+ reports a mock object without expectations as a PHPUnit notice, which fails CI.

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
- Every test class declares its coverage target: `#[CoversClass(ClassName::class)]` for classes, `#[CoversTrait]` for traits, `#[CoversNothing]` for kernel tests and interface contracts (`CoversClass` on an interface is a PHPUnit warning; the suite fails on warnings, notices and deprecations that `src/` triggers directly, including silenced ones such as Symfony's `trigger_deprecation()`; deprecations raised by vendor code on its own, and Doctrine's (off unless `DOCTRINE_DEPRECATIONS=trigger`), are not reported, so DBAL deprecations are caught by PHPStan and `OutboxTableTest`)
- `#[DataProvider('providerMethodName')]` on test methods — provider methods must be `public static`
- Name test methods `test_snake_case_description`; do not use `#[Test]`
- Tests that depend on features of newer dependencies (e.g. `DecodeFailedMessageMiddleware` from Messenger 8.1, `Column::getTypeName()` from DBAL 4.5) are guarded with `#[RequiresMethod]` or a `method_exists()` check in a helper, because CI also runs with the lowest supported versions (Symfony 7.4, DBAL 4.3)
- The suite runs on PHPUnit 11.5 (PHP 8.2), 12.5 (PHP 8.3) and 13 (PHP 8.4+): use only APIs all three have (keep `expectExceptionMessage()`, which PHPUnit 13.3 deprecates without a replacement in 11.5; no `any()` matcher)
- Never write `self::assertTrue(true)`: assert the observable outcome, or use `$this->expectNotToPerformAssertions()` when "does not throw" is the behaviour

## Immutability Verification

When testing immutable objects with `with*()` methods, assert both that a new instance is returned (`assertNotSame`) and that the original is unchanged. See `MessageMetadataStampTest` for the pattern.
