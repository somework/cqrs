# Idempotency bridge

The bundle provides dispatch-side message deduplication by bridging its
`IdempotencyStamp` to Symfony's `DeduplicateStamp` via `IdempotencyStampDecider`.
When a message carries an `IdempotencyStamp`, the decider converts it to a
`DeduplicateStamp` that Symfony's `DeduplicateMiddleware` uses to prevent duplicate
processing.

## How it works

The `IdempotencyStampDecider` runs in the stamp pipeline for all message types. When
it finds an `IdempotencyStamp` in the stamps array, it:

1. Removes the `IdempotencyStamp`
2. Computes a FQCN-namespaced key: `MessageClass::key`
3. Creates a `DeduplicateStamp` with the namespaced key, the configured TTL, and
   non-blocking mode
4. Adds the `DeduplicateStamp` to the stamps array

This conversion requires `symfony/lock` at runtime. The decider checks for the
presence of `Symfony\Component\Lock\Key` via `class_exists()`. If `symfony/lock` is
not installed, the decider is a no-op and silently skips conversion.

## Usage

Attach an `IdempotencyStamp` when dispatching a message to prevent duplicate
processing of the same logical operation:

```php
use SomeWork\CqrsBundle\Stamp\IdempotencyStamp;

$commandBus->dispatch(new ProcessPayment($orderId), stamps: [
    new IdempotencyStamp($orderId),
]);
```

The idempotency key should uniquely identify the operation. Using a domain
identifier like an order ID ensures that dispatching the same payment command
twice (e.g., due to a user double-click) results in only one execution.

The `IdempotencyStamp` constructor rejects empty strings with an
`InvalidArgumentException`.

## Configuration

```yaml
somework_cqrs:
    idempotency:
        enabled: true
        ttl: 300
```

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `true` | Enables the `IdempotencyStamp` to `DeduplicateStamp` bridge. When `false`, the `IdempotencyStampDecider` is not registered. |
| `ttl` | `300` | Default lock TTL in seconds for deduplication. After this period, the same key can be dispatched again. |

## Requirements

`symfony/lock` must be installed for deduplication to work. The bundle declares it
as a `suggest` dependency in `composer.json`:

```bash
composer require symfony/lock
```

If `symfony/lock` is not installed, the `IdempotencyStampDecider` is a no-op:
`IdempotencyStamp` will remain in the stamps array unconverted, and no
deduplication occurs. A compile-time warning is emitted when idempotency is
enabled but `DeduplicateStamp` dependencies are unavailable.

## Key namespacing

Deduplication keys are namespaced by the message's fully qualified class name to
prevent collisions across different message types. The namespaced key format is:

```
App\Application\Command\ProcessPayment::order-123
```

This means the same raw key (e.g., `order-123`) used on two different message
types produces different `DeduplicateStamp` keys. Each message type has its own
deduplication scope.

## Limitations

The idempotency bridge provides **dispatch-side, best-effort deduplication**. Be
aware of these constraints:

- **Dispatch-side only.** Deduplication happens at the point of dispatch, not at
  the point of consumption. If the same message reaches the transport through a
  different code path (e.g., manual Messenger dispatch without `IdempotencyStamp`),
  it will not be deduplicated.

- **Lock released on retry.** Symfony issue
  [#61917](https://github.com/symfony/symfony/issues/61917):
  `DeduplicateMiddleware` releases the lock when a message is retried. This means
  a retried message will not be deduplicated against new dispatches of the same
  key during the retry window.

- **Non-blocking lock acquisition.** The `DeduplicateStamp` is created with
  non-blocking mode (`false` as the third argument). If two concurrent dispatches
  race, both may proceed if the lock is not yet acquired by the time the second
  dispatch checks.

- **Best-effort guarantee.** For strong idempotency guarantees, implement
  consume-side deduplication in your handlers (e.g., check a database unique
  constraint or an idempotency key table before processing).
