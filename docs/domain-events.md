# Domain events recorded by entities

An entity records what happened to it as immutable events, and the bundle stores them in the
[transactional outbox](outbox.md) when the entity manager flushes, **in the transaction of the
change**. The relay dispatches them afterwards, like any stored message. An event exists if and
only if its change committed, and no handler has to rebuild it from the state of the entity or
remember to dispatch it.

```php
$article = $this->articles->find($command->articleId);
$article->rename($command->title);   // records ArticleRenamed
// The "doctrine_transaction" middleware flushes when the handler returns: the UPDATE and the
// outbox row of ArticleRenamed commit together, with the command as cause of the event.
```

!!! note "Stability"
    `RecordsEvents`, `RecordsEventsTrait` and `RecordedEventsPublisher` are part of the public API
    (`@api`). The listener (`Doctrine\RecordedEventsListener`) is internal.

## Domain events and integration events

The events an entity records are **domain events**: facts of its own model (`ArticleRenamed`,
`TaskCompleted`), named in its language and shaped by what its handlers need. They change when the
model changes, and they are meant for the application itself: its projections, process managers
and side effects.

**Integration events** are a published contract: what other services (or other teams) may rely
on. They are versioned, change slowly, carry only what consumers are allowed to see, and often go
to another transport (a broker topic, a webhook).

Keep them apart. Entities record domain events; a handler of the domain event maps it to the
integration event and dispatches that one through the outbox. Both are stored in the outbox, so the
mapping costs no atomicity: the handler runs when the relay hands it the domain event, and the
integration event is stored in the transaction of that handling.

```php
namespace App\Integration;

use App\Blog\Event\ArticleRenamed;               // domain event, recorded by the entity
use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\EventBusInterface;

#[AsEventHandler(event: ArticleRenamed::class)]
final class PublishArticleRenamed
{
    public function __construct(private readonly EventBusInterface $eventBus)
    {
    }

    public function __invoke(ArticleRenamed $event): void
    {
        // The contract other services consume: versioned, only public data.
        $this->eventBus->dispatch(
            new ArticleTitleChangedV1(articleId: $event->articleId, title: $event->title),
            DispatchMode::OUTBOX,
        );
    }
}
```

```yaml
# Route the contract to its own transport; the domain events stay inside the application.
somework_cqrs:
    transports:
        event_async:
            default: [events]            # domain events: handled by the application's workers
            map:
                App\Integration\ArticleTitleChangedV1: [integration]
```

The mapping handler stores into the outbox, so it needs a transaction: run the event bus with the
`doctrine_transaction` middleware, whether a worker consumes the domain events or the relay hands
them to a `sync://` transport in its own process. `#[Outbox(transport: 'integration')]` on the
contract class works as well as `DispatchMode::OUTBOX`. The integration event carries the domain
event as cause and keeps its correlation id. Never record integration events in entities: the model
would then depend on what other services consume.

## Requirements

- `doctrine/orm` 3 and `doctrine/doctrine-bundle` (2.18 or 3.1+), with `doctrine.orm` configured.
- The transactional outbox on its DBAL storage, on the connection of the entity managers whose
  entities record events: `outbox.enabled: true`, and no `outbox.storage` (a custom storage is tied
  to no connection and may not check the transaction; decorating `somework_cqrs.outbox.storage` is
  fine).
- The outbox table must exist before the first flush of a recording entity: the automatic setup
  never runs inside a transaction. Create it with `somework:cqrs:outbox:setup`, a Doctrine migration
  or `doctrine:schema:create` (see [Creating the table](outbox.md#creating-the-table)).

```yaml
# config/packages/somework_cqrs.yaml
somework_cqrs:
    outbox:
        enabled: true
        connection: default        # the connection of the entity manager
    doctrine_events:
        enabled: true              # a plain boolean, no %env()%
```

Without doctrine/orm, without the outbox or with `outbox.storage`, the container fails to compile
with an `InvalidConfigurationException`; without DoctrineBundle (the listener would never be
registered), with a `LogicException`.

## Recording events

An entity implements `RecordsEvents` and records events with the `recordThat()` method of
`RecordsEventsTrait`, in the methods that change it:

```php
namespace App\Blog;

use App\Blog\Event\ArticlePublished;
use App\Blog\Event\ArticleRenamed;
use Doctrine\ORM\Mapping as ORM;
use SomeWork\CqrsBundle\Contract\RecordsEvents;
use SomeWork\CqrsBundle\Contract\RecordsEventsTrait;

#[ORM\Entity]
class Article implements RecordsEvents
{
    use RecordsEventsTrait;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private string $id,
        #[ORM\Column]
        private string $title,
    ) {
    }

    public static function publish(string $id, string $title): self
    {
        $article = new self($id, $title);
        $article->recordThat(new ArticlePublished($id, $title));

        return $article;
    }

    public function rename(string $title): void
    {
        if ($title === $this->title) {
            return;
        }

        $this->title = $title;
        $this->recordThat(new ArticleRenamed($this->id, $title));
    }
}
```

- **Events stay DTOs**: final classes with public readonly properties implementing `Event`, as for
  any event of the bundle ([message design](usage.md)). Record the values the event needs when it is
  recorded; entities never attach stamps.
- **The contract**: `recordedEvents()` returns the events recorded since the last release, oldest
  first, and keeps them (entity unit tests read it); `releaseEvents()` removes and returns them, and
  the bundle calls it once they are stored. An entity may implement the interface itself instead of
  using the trait.
- **The trait's property is not mapped**: Doctrine neither persists nor loads it, and a loaded
  entity starts without events.
- **Per-event policies** come from the configuration as for any event: transports
  (`transports.event_async`, `#[Outbox(transport: …)]`, `#[Asynchronous(transport: …)]`), retry,
  serializer, metadata, and custom [stamp deciders](middleware.md).

## How it works

The listener runs in `preFlush` and `onFlush` after the other listeners of the entity manager
(priority -1024), and in `postFlush` before them (the highest priority):

```
command bus: CausationIdMiddleware (the command is the cause) → doctrine_transaction: BEGIN
  → handler: $article->rename() records ArticleRenamed
  ← $entityManager->flush()
      preFlush:  refuses a flush of entities with events outside a transaction, before Doctrine
                 computes the changes (the unit of work is left as it was)
      onFlush:   collects the entities with events (new, changed, removed or unchanged), and the
                 entities of recording classes about to be deleted
      SAVEPOINT → UPDATE article → RELEASE SAVEPOINT
      postFlush: adds the entities that recorded events during the flush,
                 EventBusInterface::dispatch($event, DispatchMode::OUTBOX) for each event:
                 the stamp pipeline and the middleware of the event bus run, the row is
                 INSERTed in the open transaction (OutboxStoredStamp), then the events are released;
                 the other postFlush listeners run after it
  ← COMMIT: the UPDATE and the outbox rows commit together
relay (later): dispatches ArticleRenamed on buses.event_async (else buses.event)
```

- **Always the outbox.** Recorded events are always stored with `DispatchMode::OUTBOX`, whatever
  `dispatch_modes.event`, `#[Outbox]` or `#[Asynchronous]` say: a synchronous dispatch would run
  handlers inside the flush, and an asynchronous one would send them before the `COMMIT`. For
  handling right after the commit, in the same process, relay them to a `sync://` transport (see
  [Handling the events](#handling-the-events)).
- **Always a transaction.** A flush of entities with events outside a transaction on the outbox
  connection is refused with `OutboxRequiresTransactionException`, whatever
  `outbox.require_transaction` says. A connection with `auto_commit: false` is always in a
  transaction.
- **Causation.** Stored while a command handler runs (the `doctrine_transaction` middleware flushes
  inside the bundle's `CausationIdMiddleware`), the events continue the flow of the command: same
  correlation id, the command as cause. A flush outside handlers (in a console command, with
  `wrapInTransaction()`) gives each event a fresh correlation id.
- **Never deferred.** The rows are written before the `COMMIT`, also when the event bus defers
  events by default (`dispatch_after_current_bus`).
- **Released after storing.** The events stay recorded until every one is stored: a refused flush
  keeps them for the next one.
- **The event bus interface.** The listener dispatches through `EventBusInterface` (not the
  concrete bus): a decorator of the interface runs, and `FakeEventBus` receives the events in
  tests. A decorator must keep `DispatchMode::OUTBOX` and return the envelope of the bus (with its
  `OutboxStoredStamp`), or the flush fails.

## What happens when

| Situation | Result |
|---|---|
| Handler on a bus with `doctrine_transaction` (or DoctrineBridge 8.2's `DoctrineDbalTransactionMiddleware` and a flush in the handler) | Rows stored before the `COMMIT`; a later failure of the handler rolls back the changes and the rows. |
| Asynchronous command in a worker | Same. When the message is retried, every handler runs again ([delivery guarantees](outbox.md#delivery-guarantees)). |
| `wrapInTransaction()`, `Connection::transactional()` outside handlers | Stored; fresh correlation per event. |
| Plain `flush()` of entities that recorded events before it | `OutboxRequiresTransactionException` from `preFlush`, before Doctrine computes the changes: nothing is written, the entity manager stays open, the entities keep their events, and the next flush in a transaction writes and stores everything. |
| Plain `flush()`, and an entity with events that only `onFlush` sees (a new entity reached by cascade, or persisted by another `preFlush` listener) | Refused as above, but Doctrine computed the changes already: the entity manager is closed, since the flush could not be retried on it. |
| Plain `flush()`, events recorded during it (lifecycle callbacks) | The changes are already committed: `OutboxRequiresTransactionException` with `afterCommit: true`, and the entity manager is closed (Doctrine skips its cleanup after an exception in `postFlush`); the events are not stored. Record events in callbacks only inside a transaction. |
| The flush fails (a constraint, an optimistic lock) | `postFlush` never runs: no rows, also when the caller catches the exception and commits. |
| The store fails after the flush wrote (missing table, a rate limiter, a middleware rejecting the event) | The transaction is marked rollback-only, the entity manager is closed, the exception is rethrown. A caller that catches it and commits gets DBAL's `CommitFailedRollbackOnly`. The unreleased events are lost with the detached entities: run the operation again. |
| The caller's `COMMIT` fails after the flush (a deferred constraint) | The rows roll back with the changes; the events were already released. Run the operation again. |
| Another `postFlush` listener fails, clears the entity manager or flushes again | It runs after the store: the rows are in the transaction (a caller that catches the failure and commits commits them with the changes), and the events of its own flush are stored by that flush. |
| The entity manager is cleared, closed or reset during the flush (an `onFlush` listener, a lifecycle callback), or an event-bus middleware flushes, persists or removes entities, or records events, while the events are stored | `LogicException` naming the fix; the transaction is rollback-only and the entity manager closed. |
| Relay | Dispatched on `buses.event_async` (else `buses.event`) to the transport of the event; events their handlers record get the relayed event as cause. Duplicates are possible after a failure ([delivery guarantees](outbox.md#delivery-guarantees)). |
| `FakeEventBus` in tests | Receives the events with `DispatchMode::OUTBOX` and returns an `OutboxStoredStamp`; the transaction rule still applies. |

### Failure semantics

A failure after the flush wrote its changes cannot leave them without their events, nor commit
part of the events: the listener marks the transaction rollback-only (`Connection::setRollbackOnly()`)
and closes the entity manager before rethrowing. Code that catches the exception and goes on fails
at `COMMIT`. This also ends a batch that flushes item by item in savepoints: a failure of one item
dooms the whole transaction. Do not map rate limiters to recorded events.

A refused flush (no transaction, another connection) happens before `BEGIN`: nothing is written
and the entities keep their events. Refused in `preFlush` (the usual case), the entity manager stays
open and the flush can be retried in a transaction; refused in `onFlush` (an entity with events that
only Doctrine's computation of the changes found), it is closed.

## What is covered, and what is not

The listener collects, in `onFlush`, the scheduled insertions and every managed entity with
events (also the unchanged ones, which the flush does not schedule), and the entities of recording
classes about to be deleted (they leave the identity map), then rescans the identity map in
`postFlush`. Only the classes that can record events are looked at; uninitialized proxies are
skipped (they recorded nothing, and loading them would query, or fail for a deleted row).

- **Deletions.** An entity may record its event before `remove()` or during the flush
  (`#[ORM\PostRemove]`); the `DELETE` sets a generated id to `null`, so record the id before. An
  entity persisted and removed before a flush is never written, and its events are dropped with it.
- **Ids.** Prefer ids the application generates (UUIDs). With database-generated ids (`IDENTITY`),
  record the event in `#[ORM\PostPersist]`, which runs during the flush once the id is known, and
  flush inside a transaction.
- **Order.** The events of one entity keep their order; across entities the order follows the unit
  of work (new entities first), deterministic but not the order in which they were recorded. Use
  [`SequenceAware`](#numbering-the-events-of-an-aggregate) when consumers need an order.
- **Other listeners.** Entities an `onFlush` listener persists after the bundle's listener (below
  -1024) are covered. Not covered: removals scheduled by such listeners (the entity leaves the
  identity map before `postFlush`). Events recorded by `postFlush` listeners, which run after the
  store, are stored by the next flush. Doctrine does not support `flush()` inside `onFlush`
  listeners and lifecycle callbacks, and neither does the listener.
- **Managed entities changed by event-bus middleware** while the events are stored are written by
  the next flush; new or removed entities make the flush fail (Doctrine would drop them).
- **Nothing produces events** that the unit of work does not see: DQL `UPDATE`/`DELETE`, raw DBAL
  statements, entities cleared (`$entityManager->clear()`) before the flush, and embeddables.
- **Never flushed.** Events recorded on entities that are never flushed stay on the objects and are
  lost with them.
- **Other connections.** The listener is on every entity manager. One on another connection than
  `outbox.connection` whose entities record events is refused before `BEGIN` (`LogicException`);
  entity managers that share the outbox connection work.

## Handling the events

The relay dispatches each stored event on `buses.event_async` (else `buses.event`) to the
transport of the event (`transports.event_async`, `#[Outbox(transport: …)]`, or
`framework.messenger.routing`):

- **In a worker**: a persistent transport and `messenger:consume`, with Messenger's retries.
- **Right after the commit, in the relay's process**: a `sync://` transport. The handlers run when
  the relay sends the row, each dispatch in the transaction of the bus's `doctrine_transaction`
  middleware; the events their entities record are stored in it, with the relayed event as cause.
  With `outbox.relay_on_terminate` (development), the relay runs right after each request or
  command that stored events.

```yaml
framework:
    messenger:
        buses:
            event.bus:
                middleware: [doctrine_transaction]
        transports:
            events: 'sync://'

somework_cqrs:
    buses:
        event: event.bus
    transports:
        event_async:
            default: [events]
```

The [example application](https://github.com/somework/cqrs/tree/main/docs/example-app) runs this
setup on SQLite.

## Numbering the events of an aggregate

The bundle numbers nothing: an event that implements [`SequenceAware`](event-ordering.md) returns
the number its entity gave it. Keep the counter on the entity and guard it against concurrent
changes, with a version column (optimistic lock) or a pessimistic lock:

```php
#[ORM\Entity]
class Account implements RecordsEvents
{
    use RecordsEventsTrait;

    #[ORM\Column]
    private int $sequence = 0;          // the number of the last recorded event

    #[ORM\Version]
    #[ORM\Column]
    private int $version = 1;           // a concurrent change fails instead of reusing numbers

    public function credit(int $amount): void
    {
        $this->balance += $amount;
        $this->recordThat(new AccountCredited($this->id, $amount, ++$this->sequence));
    }
}
```

A separate counter column keeps the numbers dense (the version also changes without events);
`AccountCredited::getSequenceNumber()` returns `$sequence`, and each stored event carries an
`AggregateSequenceStamp`.

## Aggregates without the ORM

A repository on DBAL publishes the events of its aggregates with `RecordedEventsPublisher`, in the
transaction that writes them, with the same guards: the transaction check on the outbox connection
(`OutboxRequiresTransactionException`, whatever `require_transaction` says), every event through
`EventBusInterface::dispatch()` with `DispatchMode::OUTBOX`, the `OutboxStoredStamp` check, and the
release once every event is stored. A failure makes the transaction rollback-only, and the events
stay recorded.

```php
use Doctrine\DBAL\Connection;
use SomeWork\CqrsBundle\Outbox\RecordedEventsPublisher;

final class DbalOrderRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RecordedEventsPublisher $events,
    ) {
    }

    public function save(Order $order): void
    {
        $this->connection->transactional(function () use ($order): void {
            $this->connection->update('orders', ['status' => $order->status()], ['id' => $order->id()]);
            $this->events->publish($order);   // after writing it, in the same transaction
        });
    }
}
```

The service exists with the outbox on its DBAL storage; `doctrine_events` is not needed. In unit
tests of such a repository, build the publisher with a `FakeEventBus` and a connection
(`new RecordedEventsPublisher($fakeEventBus, $connection)`).

## Testing

- **Entities**: assert on `recordedEvents()` after calling a method; nothing else is needed.

  ```php
  $article = Article::publish($id, 'Draft');
  $article->rename('Final');

  self::assertEquals([new ArticlePublished($id, 'Draft'), new ArticleRenamed($id, 'Final')], $article->recordedEvents());
  ```

- **Handlers**: with `FakeEventBus` as `EventBusInterface` in the test container
  ([Testing](testing.md#recorded-events)), a flush in a transaction hands it the events with
  `DispatchMode::OUTBOX`; `assertStoredInOutbox()` checks them.
- **Transactions in tests.** The rule applies in tests too: flush inside `wrapInTransaction()` or
  through a bus with `doctrine_transaction`. `dama/doctrine-test-bundle` opens its transaction
  below DBAL, so the connection does not see it, and a plain `flush()` of recording entities is
  still refused in its tests.

## Upgrading and limits

- Enabling the feature refuses plain flushes of recording entities: check flushes outside handlers
  (console commands, fixtures, test suites that open a transaction in `setUp()` below DBAL).
- Entities with members named `recordThat()`, `recordedEvents()`, `releaseEvents()` or a
  `$recordedEvents` property clash with the trait.
- Event-bus middleware must not write through the entity manager, and `EventBusInterface`
  decorators must keep the mode.
- The listener scans the identity map three times per flush (see [Cost](#cost)).

## Cost

Each flush scans the identity map three times (in `preFlush`, `onFlush` and `postFlush`), only for
the entity classes that can record events. Measured with `php tests/Benchmark/recorded-events.php`
(10 000 managed entities, 1% of them changed and recording an event, flush in a transaction, median
of 9 runs):

| Flush of 10 000 managed entities | SQLite, PHP 8.4 | PostgreSQL 16, PHP 8.4 | SQLite, PHP 8.2 |
|---|---|---|---|
| Recording class, without the listener | 25.7 ms | 40.5 ms | 18.5 ms |
| Recording class, listener time (scans, `FakeEventBus`) | 4.2 ms | 4.4 ms | 2.9 ms |
| Recording class, listener time (scans and 100 outbox rows) | 7.4 ms | 20.9 ms | 5.9 ms |
| Class that records nothing, listener time | 0.0 ms | 0.0 ms | 0.0 ms |

The scans cost about 0.15 µs per managed entity of a recording class and scan; storing the rows
costs what any outbox dispatch costs.

See also [Transactional outbox](outbox.md), [Event ordering](event-ordering.md) and
[Troubleshooting](troubleshooting.md#events-recorded-by-entities).
