# Retry strategy bridge

The bundle bridges per-message `RetryPolicy` configuration to Symfony Messenger's
transport-level retry mechanism via `CqrsRetryStrategy`. This lets you define retry
parameters (max retries, initial delay, multiplier) on individual message policies
and have them applied automatically at the transport level, without manually
configuring Symfony's `MultiplierRetryStrategy` per transport.

## How it works

`CqrsRetryStrategy` implements Symfony's `RetryStrategyInterface`. When a message
fails and Messenger asks whether to retry, the strategy resolves the message's
`RetryPolicy` via the bundle's resolver hierarchy (exact class, parent classes,
interfaces, type default).

If the resolved policy also implements `RetryConfiguration`, the strategy reads its
parameters and computes exponential backoff:

```
delay = initialDelay * (multiplier ^ retryCount)
```

Optional jitter adds random variance to prevent thundering herd problems when many
messages fail simultaneously. A configurable max delay cap prevents overflow with
large multiplier and retry count combinations.

If the resolved policy does NOT implement `RetryConfiguration`, the strategy
delegates to the original transport retry strategy (preserved as a fallback).

## Configuration

Enable the retry strategy bridge by mapping your Messenger transport names to CQRS
message types:

```yaml
somework_cqrs:
    retry_strategy:
        transports:
            async: command
            async_events: event
        jitter: 0.1
        max_delay: 60000
```

| Option | Default | Description |
|--------|---------|-------------|
| `transports` | `[]` | Maps Messenger transport names to CQRS message types (`command`, `query`, or `event`). Each mapped transport uses `CqrsRetryStrategy` with the corresponding `RetryPolicyResolver`. |
| `jitter` | `0.0` | Jitter factor (0.0-1.0) applied to computed retry delays. Adds random variance to prevent thundering herd when many messages retry at the same time. |
| `max_delay` | `0` | Maximum delay in milliseconds. `0` means no cap. When set, any computed delay exceeding this value is clamped to the cap. |

The `transports` map is keyed by Messenger transport name. The value tells the
bundle which `RetryPolicyResolver` to use for messages on that transport. For
example, `async: command` means the `async` transport will use the command-type
retry policy resolver to look up per-message retry configuration.

## Per-message retry policy

Define retry policies per message type using the `retry_policies` config section.
The built-in `ExponentialBackoffRetryPolicy` implements both `RetryPolicy` and
`RetryConfiguration`:

```yaml
# config/services.yaml
services:
    app.retry.exponential:
        class: SomeWork\CqrsBundle\Support\ExponentialBackoffRetryPolicy
        arguments:
            $maxRetries: 5
            $initialDelay: 1000      # milliseconds
            $multiplier: 2.0

# config/packages/cqrs.yaml
somework_cqrs:
    retry_policies:
        command:
            default: SomeWork\CqrsBundle\Support\NullRetryPolicy
            map:
                App\Application\Command\ProcessPayment: app.retry.exponential
    retry_strategy:
        transports:
            async: command
        jitter: 0.1
        max_delay: 60000
```

The `ExponentialBackoffRetryPolicy` constructor accepts three parameters:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `$maxRetries` | `3` | Maximum number of retry attempts before the message is rejected |
| `$initialDelay` | `1000` | Initial delay in milliseconds before the first retry |
| `$multiplier` | `2.0` | Multiplier applied to the delay on each subsequent retry |

With the defaults, retry delays follow: 1000ms, 2000ms, 4000ms (3 attempts total).

## Implementing a custom RetryPolicy with RetryConfiguration

To define custom retry behavior that the retry strategy bridge can read, implement
both `RetryPolicy` and `RetryConfiguration`:

```php
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\RetryConfiguration;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class FixedIntervalRetryPolicy implements RetryPolicy, RetryConfiguration
{
    public function __construct(
        private readonly int $maxRetries = 5,
        private readonly int $delay = 2000,
    ) {}

    /** @return list<StampInterface> */
    public function getStamps(object $message, DispatchMode $mode): array
    {
        return [];
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getInitialDelay(): int
    {
        return $this->delay;
    }

    public function getMultiplier(): float
    {
        return 1.0; // fixed interval, no exponential growth
    }
}
```

Register it as a service and map it to specific messages via `retry_policies.command.map`
or `retry_policies.event.map`.

## Fallback behavior

When a message's resolved `RetryPolicy` does NOT implement `RetryConfiguration`,
`CqrsRetryStrategy` delegates to the original transport retry strategy. This
fallback is the transport's default `RetryStrategyInterface` that was configured in
`framework.messenger.transports.*.retry_strategy`.

The bundle's `CqrsRetryStrategyPass` compiler pass replaces each mapped transport's
retry strategy in Messenger's `retry_strategy_locator` with `CqrsRetryStrategy`.
The original strategy is preserved and injected as the fallback argument.

When no fallback exists (the transport had no prior retry strategy configured):

- `isRetryable()` returns `true` (let Symfony's default behavior decide)
- `getWaitingTime()` returns `0` (no delay)

## Combining with Symfony retry

The retry strategy bridge replaces (not wraps) the transport's default retry
strategy. Messages whose `RetryPolicy` implements `RetryConfiguration` get
per-message retry parameters. All other messages on the same transport fall through
to the original strategy that was configured in `framework.messenger.transports`.

This means you can have per-message retry policies for specific commands while
keeping Symfony's default `MultiplierRetryStrategy` as the fallback for everything
else on the same transport.
