# Retry policies

Retry settings can be defined per message. Two contracts are involved:

- **`RetryPolicy`** (`SomeWork\CqrsBundle\Contract\RetryPolicy`) is resolved for every
  dispatched message. Its `getStamps()` result is added to the envelope in the stamp
  pipeline.
- **`RetryConfiguration`** (`SomeWork\CqrsBundle\Contract\RetryConfiguration`) is an
  optional second interface for a policy. It exposes the numbers Messenger needs to retry a
  failed message. `CqrsRetryStrategy` reads them on the transports you list under
  `retry_strategy.transports`.

Retries are a worker concern. Messenger retries messages that failed while a worker
consumed them from a transport. When a message is handled synchronously and fails, the
exception propagates to the caller, and no retry happens.

## Contracts

```php
<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use Symfony\Component\Messenger\Stamp\StampInterface;

interface RetryPolicy
{
    /**
     * @return list<StampInterface>
     */
    public function getStamps(object $message, DispatchMode $mode): array;
}

interface RetryConfiguration
{
    /** Maximum number of retry attempts before the message is rejected. */
    public function getMaxRetries(): int;

    /** Initial delay in milliseconds before the first retry attempt. */
    public function getInitialDelay(): int;

    /** Multiplier applied to the delay for each subsequent retry attempt. */
    public function getMultiplier(): float;
}
```

The bundle calls `getStamps()` in the stamp pipeline (`RetryPolicyStampDecider`, priority
200) for commands, queries and events, in every dispatch mode. Return an empty array when
the policy only configures transport retries.

## Built-in policies

| Class | Stamps | `RetryConfiguration` | Notes |
|-------|--------|----------------------|-------|
| `SomeWork\CqrsBundle\Policy\NullRetryPolicy` | none | no | The default policy of every message type. Transports keep their own Messenger retry strategy. |
| `SomeWork\CqrsBundle\Policy\ExponentialBackoffRetryPolicy` | none | yes | Constructor `(int $maxRetries = 3, int $initialDelay = 1000, float $multiplier = 2.0)`. Rejects `$maxRetries < 0`, `$initialDelay < 1` and `$multiplier <= 0`. |

Both are registered as services under their class names. The `ExponentialBackoffRetryPolicy`
service uses the constructor defaults, and `somework_cqrs.exponential_backoff_retry_policy`
is an alias of it. For other values, register your own service:

```yaml
# config/services.yaml
services:
    app.retry.payment:
        class: SomeWork\CqrsBundle\Policy\ExponentialBackoffRetryPolicy
        arguments:
            $maxRetries: 5
            $initialDelay: 1000   # milliseconds
            $multiplier: 2.0
```

## Mapping policies to messages

`retry_policies` has a global `default` and one section per message type. Each section has
an optional `default` service id and a `map` from message class or interface names to
service ids:

```yaml
# config/packages/somework_cqrs.yaml
somework_cqrs:
    retry_policies:
        default: SomeWork\CqrsBundle\Policy\NullRetryPolicy
        command:
            default: ~
            map:
                App\Application\Command\ProcessPayment: app.retry.payment
                App\Application\Command\TalksToPaymentGateway: SomeWork\CqrsBundle\Policy\ExponentialBackoffRetryPolicy
        event:
            default: ~
            map: {}
        query:
            default: ~
            map: {}
```

A message's policy is looked up in this order: exact class, parent classes, interfaces,
the section's `default`, then the global `default`. Map keys must be existing class or interface names (a
leading `\` is allowed), and a typo fails container compilation. Each value must be a
service that implements `RetryPolicy`.

## Transport retry strategy

A `RetryConfiguration` only takes effect on transports that you hand over to the bundle:

```yaml
# config/packages/somework_cqrs.yaml
somework_cqrs:
    retry_strategy:
        transports:
            async_commands: command
            async-events: event
        jitter: 0.1
        max_delay: 60000
```

| Option | Default | Description |
|--------|---------|-------------|
| `transports` | `{}` | Maps a Messenger transport name to `command`, `query` or `event`. The type selects the `retry_policies` section used to resolve policies for messages on that transport. |
| `jitter` | `0.0` | Random variation of each computed delay, between `0.0` and `1.0`. `0.1` means ±10 %. |
| `max_delay` | `0` | Upper bound for each delay, in milliseconds. `0` means no cap. |

For each listed transport, `CqrsRetryStrategyPass` replaces the transport's entry in
Messenger's `messenger.retry_strategy_locator` with a `CqrsRetryStrategy`. The transport's
original strategy is kept as the fallback. Keep the following in mind:

- **Keys are Messenger transport names**, written exactly as they appear under
  `framework.messenger.transports`. They are not normalised: a transport named
  `async-events` must be listed as `async-events`, not `async_events`.
- **Unknown transports are rejected.** Compilation fails with
  `Transport "<name>" configured under "somework_cqrs.retry_strategy.transports" is not a Messenger transport. Known transports: "..."`.
- **Each message uses its own type.** A command received from the transport is resolved
  against `retry_policies.command`, an event against `retry_policies.event`, whatever type
  the transport is mapped to; commands and events can share a transport. The mapped type
  only applies to messages that are neither commands, queries nor events.

## How CqrsRetryStrategy decides

When a worker fails to handle a message from a mapped transport, Messenger asks the
strategy two questions: whether to retry, and how long to wait. `CqrsRetryStrategy`
resolves the message's policy and continues as follows.

**The policy implements `RetryConfiguration`:**

- **Retry or not.** The message is retried while `retryCount < getMaxRetries()`. The retry
  count comes from Messenger's `RedeliveryStamp` and is `0` on the first failure, so
  `maxRetries: 3` allows three retries (four attempts in total).
- **Delay.** It is computed in three steps:
  1. `initialDelay × multiplier^retryCount`;
  2. capped at `max_delay` (when it is greater than 0);
  3. multiplied by a random factor between `1 - jitter` and `1 + jitter`, then capped at
     `max_delay` again.

  Because the cap comes before the jitter, jitter still spreads retries that hit the cap,
  and a delay never exceeds `max_delay`.

**The policy does not implement `RetryConfiguration`** (for example `NullRetryPolicy`): both
questions go to the transport's original strategy. That strategy is whatever
`framework.messenger.transports.<name>.retry_strategy` configures. By default, that is
Messenger's `MultiplierRetryStrategy` with 3 retries, a 1000 ms initial delay and a
multiplier of 2, plus Messenger's own `jitter` (default 0.1). The bundle's `jitter` and
`max_delay` options do not apply to it.

With `ExponentialBackoffRetryPolicy(5, 1000, 2.0)`, `max_delay: 5000` and `jitter: 0`, the
delays are:

| Failure | Retry count | Retried? | Delay |
|---------|-------------|----------|-------|
| 1st | 0 | yes | 1000 ms |
| 2nd | 1 | yes | 2000 ms |
| 3rd | 2 | yes | 4000 ms |
| 4th | 3 | yes | 5000 ms (capped) |
| 5th | 4 | yes | 5000 ms (capped) |
| 6th | 5 | no, sent to the failure transport (if one is configured) | none |

Messenger applies its own rules before it asks the strategy:

- An exception that implements `UnrecoverableExceptionInterface` (for example
  `UnrecoverableMessageHandlingException`) is never retried.
- An exception that implements `RecoverableExceptionInterface` is always retried, and a
  delay it provides is used instead of the strategy's.

## Writing a policy with RetryConfiguration

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Retry;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\RetryConfiguration;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class FixedIntervalRetryPolicy implements RetryPolicy, RetryConfiguration
{
    public function __construct(
        private readonly int $maxRetries = 5,
        private readonly int $delay = 2000,
    ) {
    }

    /**
     * @return list<StampInterface>
     */
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
        return 1.0; // same delay before every retry
    }
}
```

Register the class as a service (autowiring does this for classes under `src/`), then map it
in `retry_policies.<type>.map`. List the transports that carry those messages under
`retry_strategy.transports`.
