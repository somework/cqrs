# Rate limiting

The bundle provides per-message-type dispatch throttling by bridging to Symfony's
native rate limiter infrastructure. No custom algorithm code is needed -- configure a
Symfony rate limiter and map it to message types.

## How it works

`RateLimitStampDecider` runs in the stamp pipeline. For each dispatched message, it
resolves the configured `RateLimiterFactory` via `RateLimitResolver`, creates a
limiter keyed by message FQCN, and consumes one token. If the token is accepted,
dispatch proceeds normally (no stamps are added -- this is a gate, not a stamp). If
rejected, `RateLimitExceededException` is thrown immediately.

## Configuration

Define a Symfony rate limiter, then map message FQCNs to it:

```yaml
# config/packages/rate_limiter.yaml (Symfony)
framework:
    rate_limiter:
        send_notification:
            policy: sliding_window
            limit: 10
            interval: '1 minute'

# config/packages/cqrs.yaml
somework_cqrs:
    rate_limiting:
        enabled: true
        command:
            map:
                App\Application\Command\SendNotification: send_notification
```

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `true` | Enables rate limiting. No-op when `symfony/rate-limiter` is not installed. |
| `command.map` | `{}` | Maps command FQCNs to Symfony rate limiter names. |
| `query.map` | `{}` | Maps query FQCNs to Symfony rate limiter names. |
| `event.map` | `{}` | Maps event FQCNs to Symfony rate limiter names. |

Rate limiting is opt-in per message. There is no default limiter -- only messages
explicitly mapped are throttled.

## Handling rate limit exceeded

When a rate limit is exceeded, `RateLimitExceededException` is thrown before the
message reaches the transport:

```php
use SomeWork\CqrsBundle\Exception\RateLimitExceededException;

try {
    $commandBus->dispatch(new SendNotification($userId));
} catch (RateLimitExceededException $e) {
    $retryAfter = $e->retryAfter;       // DateTimeImmutable
    $remaining = $e->remainingTokens;     // int
    $limit = $e->limit;                   // int
    $messageFqcn = $e->messageFqcn;       // string
}
```

The exception exposes four `public readonly` properties:

| Property | Type | Description |
|----------|------|-------------|
| `messageFqcn` | `string` | The FQCN of the throttled message class. |
| `retryAfter` | `DateTimeImmutable` | When the rate limiter will accept new tokens. |
| `remainingTokens` | `int` | Number of tokens remaining in the current window. |
| `limit` | `int` | Total token capacity of the rate limiter. |

## Requirements

`symfony/rate-limiter` must be installed for rate limiting to work:

```bash
composer require symfony/rate-limiter
```

When not installed, all rate limiting code is a no-op -- no class-not-found errors,
no container compilation failures. The bundle uses `class_exists()` guards in both
the registrar and the extension to skip registration entirely when the package is
absent.

## Sync vs async behavior

Rate limiting gates at dispatch time, before the message reaches the transport. This
means:

- **Sync dispatch:** The caller receives the `RateLimitExceededException` immediately.

- **Async dispatch:** The exception is thrown before the message is sent to the
  transport. The message never reaches the queue if rate limited.

Rate limiting does NOT apply at consumption time. Messages already in the transport
queue are not throttled on consumption.
