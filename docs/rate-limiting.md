# Rate limiting

The bundle can throttle the dispatch of selected messages with Symfony's RateLimiter
component. You define limiters under `framework.rate_limiter` and map message classes to
them. When a mapped message is dispatched, the bundle consumes one token from its limiter.
If no token is available, the bus throws `RateLimitExceededException`, and the message is
neither handled nor sent.

## Activation and requirements

Rate limiting stays inactive until a message is mapped to a limiter:

- `rate_limiting.enabled` defaults to `true`. With no mapping, nothing is registered and
  `symfony/rate-limiter` is not needed.
- Once a limiter is mapped, `symfony/rate-limiter` is required. Without it, container
  compilation fails with
  `Rate limiters are mapped under "somework_cqrs.rate_limiting" but symfony/rate-limiter is not installed.`
- `enabled: false` switches the feature off even when mappings exist. The flag decides
  which services exist, so it must be a plain boolean, not an `%env()%` value.

```bash
composer require symfony/rate-limiter
```

## Configuration

```yaml
# config/packages/rate_limiter.yaml
framework:
    rate_limiter:
        send_notification:
            policy: sliding_window
            limit: 10
            interval: '1 minute'

# config/packages/somework_cqrs.yaml
somework_cqrs:
    rate_limiting:
        enabled: true
        command:
            map:
                App\Application\Command\SendNotification: send_notification
        query:
            map: {}
        event:
            map: {}
```

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `true` | Master switch. It only has an effect once something is mapped. |
| `command.map` | `{}` | Command class or interface names mapped to `framework.rate_limiter` names. |
| `query.map` | `{}` | The same for queries. |
| `event.map` | `{}` | The same for events. |

Each map value is the name of a limiter under `framework.rate_limiter`. The bundle uses the
service `limiter.<name>`. An unknown name fails container compilation with
`... has a dependency on a non-existent service "limiter.<name>"`. The keys must be existing
class or interface names (a leading `\` is allowed). A typo fails compilation.

A message's limiter is looked up the same way as in the bundle's other per-message maps:
exact class first, then parent classes, then interfaces. Map an interface to throttle every
message that implements it. Messages without a match are not throttled, because there is no
default limiter.

## One bucket per message class

The bundle creates the limiter with the message's class name as its key:
`$factory->create($message::class)`. As a result:

- All dispatches of one message class share one bucket, whichever user, request or process
  sends them. The limit is global, not per user or per IP. The bucket is stored in the
  limiter's storage (by default `cache.rate_limiter`, a pool based on `cache.app`), so every
  server that shares that cache also shares the limit.
- When you map an interface, each implementing class gets its own bucket under the same
  policy.

For per-user or per-client limits, call Symfony's RateLimiter yourself, for example in a
controller, with a key you choose.

## Supported limiters

The resolver accepts any `Symfony\Component\RateLimiter\RateLimiterFactory`, which covers
the `fixed_window`, `sliding_window`, `token_bucket` and `no_limit` policies. On
symfony/rate-limiter 7.3 or newer, it also accepts any `RateLimiterFactoryInterface`,
including the `compound` policy that combines several limiters:

```yaml
framework:
    rate_limiter:
        per_minute:
            policy: fixed_window
            limit: 10
            interval: '1 minute'
        per_hour:
            policy: sliding_window
            limit: 100
            interval: '1 hour'
        notifications:
            policy: compound
            limiters: [per_minute, per_hour]
```

## Handling RateLimitExceededException

`SomeWork\CqrsBundle\Exception\RateLimitExceededException` extends `\RuntimeException`.
`dispatch()`, `dispatchSync()`, `dispatchAsync()` and `ask()` throw it directly, not wrapped
in a Messenger exception:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Command\SendNotification;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use SomeWork\CqrsBundle\Exception\RateLimitExceededException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class NotificationController
{
    #[Route('/notifications/{userId}', methods: ['POST'])]
    public function send(string $userId, CommandBusInterface $commandBus): JsonResponse
    {
        try {
            $commandBus->dispatch(new SendNotification($userId));
        } catch (RateLimitExceededException $e) {
            return new JsonResponse(
                ['error' => 'Too many notifications', 'limit' => $e->limit],
                429,
                ['Retry-After' => (string) max(0, $e->retryAfter->getTimestamp() - time())],
            );
        }

        return new JsonResponse(null, 202);
    }
}
```

| Property | Type | Description |
|----------|------|-------------|
| `messageClass` | `string` | Class of the throttled message. |
| `retryAfter` | `DateTimeImmutable` | When the limiter will accept a token again. |
| `remainingTokens` | `int` | Tokens left in the current window (normally `0`). |
| `limit` | `int` | Capacity of the limiter. |

All four are `public readonly`. The exception message reads
`Rate limit exceeded for "<class>". Retry after <ISO 8601 date>.`. When a logger is
available, the bundle also logs a warning with the same data.

## When the limit applies

- **At dispatch, in every mode.** `RateLimitStampDecider` has priority 225. It runs first
  among the built-in stamp deciders, before Messenger sees the message. This applies to sync and async
  dispatches alike. An async message that is over the limit never reaches the transport. A
  message that would be deferred with `DispatchAfterCurrentBusStamp` is checked when you
  call the bus, not when Messenger later dispatches it.
- **Only through the CQRS buses.** Workers consuming a transport, Messenger retries,
  messages relayed from the [outbox](outbox.md) and messages dispatched directly on a
  Messenger bus are not throttled. To limit how fast a worker processes a transport, use
  Messenger's own `framework.messenger.transports.<name>.rate_limiter` option.
