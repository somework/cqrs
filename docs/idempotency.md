# Idempotency

Attach an `IdempotencyStamp` to a dispatch, and a second dispatch of the same message class
with the same key is dropped while the first one is still locked. The bundle does not
implement deduplication itself. Its `IdempotencyStampDecider` converts the stamp into
Symfony Messenger's `DeduplicateStamp`, and Messenger's deduplicate middleware enforces it
with a lock from the Lock component.

## Requirements

- **symfony/messenger 7.3 or newer.** `DeduplicateStamp` and the deduplicate middleware
  were added in 7.3.
- **symfony/lock**.
- **`framework.lock` enabled.** FrameworkBundle adds Messenger's `deduplicate_middleware`
  to buses that use the default middleware only when the lock component is enabled. It is
  enabled automatically once `symfony/lock` is installed, unless you turned it off.
- **A shared lock store that keeps keys until their TTL.** The default stores do not work:
  `flock` and `semaphore` release a key as soon as the dispatch returns, so a second
  dispatch with the same key goes through, and `in-memory` only deduplicates within one
  process. The keys of `flock`, `semaphore`, `postgresql+advisory` and `zookeeper` stores
  are tied to the process or connection and cannot be sent with async messages: an
  asynchronous dispatch with an `IdempotencyStamp` then fails with a `LogicException` that
  names the problem. PostgreSQL advisory locks and ZooKeeper also ignore the TTL: a key stays
  locked as long as the connection that took it, which in a long-running worker can be forever. Point `framework.lock` at Redis, Memcached or a PDO/DBAL database; the
  container compilation log warns when the configured store is one of the others (for a DSN
  from an environment variable, with the value it had when the container was compiled):

```yaml
# config/packages/lock.yaml
framework:
    lock: '%env(LOCK_DSN)%'   # e.g. redis://redis:6379
```

```bash
composer require symfony/lock
```

A missing piece never breaks the build, because idempotency is enabled by default. Instead,
the `IdempotencyStamp` is ignored and nothing is deduplicated. The first message that carries
an `IdempotencyStamp` in a process logs a warning with the reason (`The IdempotencyStamp of
App\Command\ChargePayment may not prevent duplicates: …`), and the container compilation log
says the same. In debug mode, Symfony writes it to
`var/cache/<env>/<ContainerClass>Compiler.log`:

```bash
grep Idempotency var/cache/dev/*Compiler.log
```

It reports one of the following:

- `Idempotency is enabled but needs symfony/messenger ^7.3 (DeduplicateStamp) and symfony/lock; IdempotencyStamp is ignored until both are installed.`
- `Idempotency is enabled but Messenger's deduplicate middleware is not registered, so DeduplicateStamp is not enforced. Enable the lock component ("framework.lock").`
- `Idempotency is enabled but the lock store "in-memory" only deduplicates within one process. …`
- `Idempotency is enabled but the lock store "flock" releases a key as soon as the dispatch returns and only lives on one host: …`
- `Idempotency is enabled but the lock store "postgresql+advisory://…" ties its keys to one connection: …`

## Usage

```php
<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Command\ChargePayment;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use SomeWork\CqrsBundle\Exception\DuplicateMessageException;
use SomeWork\CqrsBundle\Stamp\IdempotencyStamp;

final class PaymentService
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    public function charge(string $orderId): ?string
    {
        try {
            return $this->commandBus->dispatchSync(new ChargePayment($orderId), new IdempotencyStamp($orderId));
        } catch (DuplicateMessageException $e) {
            // Dispatched recently (within the TTL): $e->deduplicationKey === 'App\Application\Command\ChargePayment::<orderId>'.
            // Usually charged already, or in progress; after a killed process the charge may not have
            // run (see "Lock lifetime"), so check the order's state before reporting success.
            return null;
        }
    }

    public function chargeLater(string $orderId): void
    {
        // A duplicate is not sent to the transport; no exception is thrown.
        $this->commandBus->dispatchAsync(new ChargePayment($orderId), new IdempotencyStamp($orderId));
    }
}
```

The key should identify the operation, for example an order id or a client-supplied request
id. Keys are global per message class: two users or tenants that send the same key for the same
message class collide, and the second message is dropped as a duplicate. Scope keys that come
from clients, e.g. `new IdempotencyStamp($tenantId.':'.$userId.':'.$requestId)`. Keys are
stored in the lock store, logged and shown in `DuplicateMessageException`: use ids, never
personal data such as e-mail addresses (see [Personal data](production.md#personal-data)).
`new IdempotencyStamp('')` throws an `InvalidArgumentException`. Stamps are variadic
arguments of every dispatch method: `dispatch($message, DispatchMode::DEFAULT, ...$stamps)`,
`dispatchSync($message, ...$stamps)`, `dispatchAsync($message, ...$stamps)` and
`ask($query, ...$stamps)`.

## How it works

`IdempotencyStampDecider` runs in the stamp pipeline for every message type, at priority 50.
When the stamps contain an `IdempotencyStamp`, it:

1. builds the key `<message FQCN>::<idempotency key>`, for example
   `App\Application\Command\ChargePayment::order-123`. The same raw key on two message
   classes gives two different locks;
2. adds `new DeduplicateStamp($key, $ttl, false)`, where `$ttl` is `idempotency.ttl` and
   `false` is Messenger's `onlyDeduplicateInQueue` flag (see below);
3. keeps the `IdempotencyStamp` on the envelope, so handlers and middleware can still read
   the key.

**A `DeduplicateStamp` passed by the caller wins.** The decider then adds nothing. Use this
approach to set a different TTL for one dispatch. The key is then yours as given, not
namespaced:

```php
<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Command\GenerateInvoice;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

final class InvoiceService
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    public function generate(string $invoiceId): void
    {
        $this->commandBus->dispatchAsync(
            new GenerateInvoice($invoiceId),
            new DeduplicateStamp(GenerateInvoice::class.'::'.$invoiceId, 3600.0),
        );
    }
}
```

Messenger's `DeduplicateMiddleware` on the target bus then tries to acquire the lock without
waiting. Acquisition is atomic in the lock store. If the lock is already held, the envelope
is returned unhandled and unsent.

## Lock lifetime

With `onlyDeduplicateInQueue = false`, the lock lives as follows:

| Situation | Lock |
|-----------|------|
| Handled synchronously, handler succeeded | Held until the TTL expires. Further dispatches with the key are dropped during that window. |
| Handled synchronously, handler threw | **Released immediately** by the bundle's `DeduplicationLockReleaseMiddleware`, so the caller can retry with the same key at once. The same applies when sending to a transport fails. |
| Handled synchronously, PHP fatal error (memory or time limit) | Released when PHP shuts down, by a shutdown function of the same middleware. |
| Handled synchronously, process killed (SIGKILL, OOM killer) | Held until the TTL expires: a retry is reported as a duplicate although the handler did not finish. Keep the TTL short for operations the caller retries, and do not treat `DuplicateMessageException` as proof that the operation succeeded. |
| Sent to a transport | Held while the message waits in the queue and while a worker handles it. Released after the worker handled it successfully. |
| Worker handler threw | Kept. It is released when a Messenger retry of the message succeeds, and otherwise expires with the TTL. |
| Queue wait or processing longer than the TTL | The lock expires, and a new dispatch with the same key goes through. |

Pick a TTL longer than the time a message typically waits in the queue plus its processing
time.

## What the caller sees when a duplicate is dropped

| Call | Result |
|------|--------|
| `CommandBus::dispatchSync()`, `QueryBus::ask()` | Throw `SomeWork\CqrsBundle\Exception\DuplicateMessageException`, because no handler result exists. Its public read-only properties are `messageClass`, `busName` and `deduplicationKey`. |
| `EventBus::dispatchSync()`, or `dispatch()` resolving to sync on either bus | No exception. No handler runs, and the returned envelope has no `HandledStamp`. |
| `dispatchAsync()`, or `dispatch()` resolving to async | No exception. The message is not sent, and the returned envelope has no `SentStamp`. |

Asynchronous dispatches carry a `DispatchAfterCurrentBusStamp` by default. When such a
dispatch happens inside a handler, Messenger defers it until the current handler finishes,
and the duplicate check runs at that later point.

## Configuration

```yaml
# config/packages/somework_cqrs.yaml
somework_cqrs:
    idempotency:
        enabled: true
        ttl: 300
```

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `true` | Registers `IdempotencyStampDecider`. The decider is also skipped when symfony/messenger 7.3+ or symfony/lock is missing. The value decides which services exist, so it must be a plain boolean, not an `%env()%` value. |
| `ttl` | `300` | Lock TTL in seconds (integer, at least 1) for every `DeduplicateStamp` the bundle creates. |

## Limitations

- **Dispatch-side only.** The conversion happens in the stamp pipeline of the CQRS buses.
  Messages dispatched directly on a Messenger bus, and messages relayed from the
  [transactional outbox](outbox.md), are not converted. Worker redeliveries are not checked
  again either. A `DeduplicateStamp` stored with `OutboxWriter` gets a key scoped to the
  row's transport (`<key>@<transport>`), so it does not deduplicate against the same key
  dispatched directly on a bus.
- **Time-bounded.** Deduplication lasts as long as the lock (see [Lock lifetime](#lock-lifetime)).
  It is not a permanent record of processed operations.
- **Only as reliable as the lock store.** The local stores (flock, semaphore, in-memory) do
  not deduplicate reliably (see [Requirements](#requirements)), and a store that loses its
  data (for example, a Redis restart) forgets the locks.

For guarantees that do not expire, check on the consuming side as well. For example, record
processed keys in a table with a unique constraint. An `EnvelopeAware` handler (see
[Receiving the envelope](usage.md#receiving-the-envelope)) can read the key with
`$this->getEnvelope()->last(IdempotencyStamp::class)?->getKey()`.
