# Upgrade Guide

## Backward Compatibility Promise

This bundle follows [Semantic Versioning](https://semver.org/). The BC promise
applies only to classes, interfaces, traits, and enums annotated with `@api` in
their class-level PHPDoc block.

- **`@api` types** follow semver: no breaking changes in minor or patch releases.
  You can safely depend on their public methods, constructor signatures, and
  return types.

- **`@internal` types** may change without notice in any release (including
  minor and patch). Do not extend, implement, or instantiate them directly in
  your application code. If you find yourself depending on an internal type,
  open an issue so we can evaluate promoting it to the public API.

## What Counts as a Breaking Change for `@api` Types

The following changes are considered breaking and will only occur in major releases:

- Removing a public method or changing its signature
- Removing a class, interface, or trait
- Adding required constructor parameters (without defaults)
- Changing return types to incompatible types
- Removing interface methods

## What is NOT a Breaking Change

The following changes may happen in any minor or patch release:

- Adding optional parameters with default values
- Adding new methods to classes
- Adding new classes or interfaces
- Bug fixes that change incorrect behavior
- Adding new `@api` or `@internal` annotations

## v0.4.0

### Bus Interfaces (DX-01)

Three new interfaces are available for type-hinting bus dependencies:

- `CommandBusInterface` (implemented by `CommandBus` and `FakeCommandBus`)
- `QueryBusInterface` (implemented by `QueryBus` and `FakeQueryBus`)
- `EventBusInterface` (implemented by `EventBus` and `FakeEventBus`)

**Recommended migration:** Replace concrete bus type-hints with interfaces:

```diff
- public function __construct(private readonly CommandBus $commandBus) {}
+ public function __construct(private readonly CommandBusInterface $commandBus) {}
```

The interfaces are autowired to the real bus implementations. For testing, override
in `services_test.yaml`:

```yaml
services:
    SomeWork\CqrsBundle\Contract\CommandBusInterface:
        class: SomeWork\CqrsBundle\Testing\FakeCommandBus
```

### Handler __invoke Signature Change (DX-04)

The `CommandHandler`, `QueryHandler`, and `EventHandler` interfaces no longer
declare a PHP type on the `__invoke()` parameter. This allows concrete handlers
to use specific message types without union type workarounds:

```diff
- public function __invoke(CreateTaskCommand|Command $command): mixed
+ public function __invoke(CreateTaskCommand $command): mixed
```

**Impact:** This is backward compatible at runtime (removing a type widens
acceptance). However, if your handler explicitly typed the parameter as `Command`,
`Query`, or `Event` to match the old interface signature, you may now use the
specific message type instead. No action is required -- existing handlers continue
to work.

Static analysis (PHPStan) continues to enforce type safety via `@template`
annotations.

## Upgrading from 1.x to 2.0

### Requirements

- **Dropped Symfony 6.4 support** -- requires Symfony 7.x.

### Exception Handling

Custom exceptions replace generic `RuntimeException` throws:

- `NoHandlerException` -- thrown when a command or query has no handler.
- `MultipleHandlersException` -- thrown when a query has more than one handler.
- `AsyncBusNotConfiguredException` -- thrown when dispatching async without a
  configured async bus.

All three are `@api` types with `public readonly` properties for programmatic
access (`$messageFqcn`, `$busName`, `$handlerCount`).

### Testing Namespace

The new `src/Testing/` namespace provides test doubles and PHPUnit assertions:

- `FakeCommandBus`, `FakeQueryBus`, `FakeEventBus` -- bus doubles that record
  dispatches without Messenger infrastructure.
- `CqrsTestCase` -- abstract PHPUnit test case with CQRS assertion helpers.
- `CqrsAssertionsTrait` -- trait with `assertDispatched()` and
  `assertNotDispatched()` for use in any test case class.
- `DispatchedMessage` -- PHPUnit constraint for custom assertions.

### Compile-Time Validation

`ValidateHandlerCountPass` now enforces exactly-one-handler for commands and
queries at container compile time. Messages with zero handlers or multiple
handlers will cause a compile error instead of a runtime exception.

### New Stamps

- `IdempotencyStamp` -- carries an idempotency key for message deduplication.
- `MessageMetadataStamp` -- now supports causation ID propagation via
  `CausationIdMiddleware` and `CausationIdContext`.

### New Retry Policy

`ExponentialBackoffRetryPolicy` is available as a named DI service
(`somework_cqrs.exponential_backoff_retry_policy`). It adds a `DelayStamp` at
dispatch time; configure Symfony's transport-level `MultiplierRetryStrategy`
for full exponential backoff across retries.

## Upgrading from 2.0 to 2.1

### ExponentialBackoffRetryPolicy behavior change

`ExponentialBackoffRetryPolicy::getStamps()` now returns an empty array. In 2.0,
it added a `DelayStamp` at dispatch time, which incorrectly delayed ALL async
messages (including first dispatch, not just retries).

In 2.1, retry delays are handled exclusively at the transport level via
`CqrsRetryStrategy`. This is a behavioral change for applications that relied
on the dispatch-time delay.

**Migration:**

1. If you used `ExponentialBackoffRetryPolicy` and want transport-level retry:
   - Add `retry_strategy.transports` config mapping your transports to message types
   - The bundle's `CqrsRetryStrategy` will read retry parameters from your policy
     and compute correct exponential backoff at the transport level

2. If you relied on the `DelayStamp` being added at dispatch for non-retry purposes:
   - Add a custom `RetryPolicy` implementation that returns
     `[new DelayStamp($ms)]` from `getStamps()`

### New retry strategy bridge

Configure `CqrsRetryStrategy` for your Messenger transports to enable per-message
retry policies at the transport level:

```yaml
somework_cqrs:
    retry_strategy:
        transports:
            async: command
        jitter: 0.1
        max_delay: 60000
```

See `docs/retry.md` for full documentation.

### New idempotency bridge

`IdempotencyStamp` is now automatically converted to Symfony's `DeduplicateStamp`
for dispatch-side deduplication:

```yaml
somework_cqrs:
    idempotency:
        enabled: true
        ttl: 300
```

Requires `symfony/lock`. See `docs/idempotency.md` for full documentation and
known limitations.

### CausationIdMiddleware configurability

`CausationIdMiddleware` and `CausationIdStampDecider` can now be disabled or
scoped to specific buses:

```yaml
somework_cqrs:
    causation_id:
        enabled: true
        buses:
            - somework_cqrs.bus.command
```

Setting `enabled: false` disables both the middleware and the paired stamp decider.
The `buses` list limits middleware injection to specific bus service IDs (empty
array means all buses, which is the default and matches 2.0 behavior).

No migration needed if you want the default behavior (enabled on all buses).

## Upgrading from 2.1 to 3.0

### Health check command

A new console command verifies CQRS infrastructure health:

```bash
php bin/console somework:cqrs:health
```

The command checks handler resolvability and transport validity. Exit codes:

- `0` -- all checks passed
- `1` -- warnings found (e.g., no handlers registered)
- `2` -- critical failures (e.g., handler not resolvable, transport not found)

Usable as a Kubernetes exec probe or CI pipeline gate. See the command output for
detailed check results.

### Event ordering

Events can now carry per-aggregate ordering metadata via the `SequenceAware`
interface and `AggregateSequenceStamp`:

```yaml
somework_cqrs:
    sequence:
        enabled: true  # default
```

Implement `SequenceAware` on your events to automatically receive an
`AggregateSequenceStamp` with `aggregateId`, `sequenceNumber`, and `aggregateType`.
See `docs/event-ordering.md` for full documentation.

Note: ordering is vocabulary only -- the stamp carries metadata but does not enforce
processing order.

### Rate limiting

Per-message-type dispatch throttling bridges to Symfony's rate limiter:

```yaml
somework_cqrs:
    rate_limiting:
        command:
            map:
                App\Application\Command\SendNotification: send_notification
```

Requires `symfony/rate-limiter`:

```bash
composer require symfony/rate-limiter
```

When not installed, rate limiting is a no-op. See `docs/rate-limiting.md` for full
documentation.

### Transactional outbox

The transactional outbox pattern persists async messages in the same database
transaction as business logic:

```yaml
somework_cqrs:
    outbox:
        enabled: true
        table_name: somework_cqrs_outbox
```

Requires `doctrine/dbal`:

```bash
composer require doctrine/dbal
```

Relay unpublished messages with:

```bash
php bin/console somework:cqrs:outbox:relay
```

When not installed, outbox services are not registered. See `docs/outbox.md` for
full documentation.

### New optional dependencies

v3.0 adds two new optional (`suggest`) dependencies:

| Package | Required for | Install |
|---------|-------------|---------|
| `symfony/rate-limiter` | Rate limiting | `composer require symfony/rate-limiter` |
| `doctrine/dbal` | Transactional outbox | `composer require doctrine/dbal` |

Both features are no-ops when their packages are not installed. No changes are
required for existing applications that do not use these features.

### No breaking changes

v3.0 introduces no breaking changes to existing `@api` types. All new features are
additive:

- New config keys (`sequence`, `rate_limiting`, `outbox`) have sensible defaults
- Event ordering is enabled by default (but only affects events implementing
  `SequenceAware`)
- Rate limiting and outbox are disabled or opt-in by default
- Existing configuration continues to work without modification
