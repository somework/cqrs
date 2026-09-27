---
paths:
  - src/Doctrine/**
  - src/Outbox/RecordedEventsPublisher.php
  - src/Contract/RecordsEvents*
---

# Domain Events Recorded by Entities

`somework_cqrs.doctrine_events` stores the events that entities (`RecordsEvents`) record in the outbox when their entity manager flushes. The guarantee: an event is stored **if and only if** the change that recorded it commits. Every path that cannot guarantee this throws.

## Flush timeline (Doctrine ORM 3/4 `UnitOfWork::commit()`)

- `onFlush` runs **before** the flush's `BEGIN`: collect the entities with events (scheduled insertions + identity map, which still holds the entities about to be deleted), check the connection (`outbox.connection`) and the transaction (`!isAutoCommit() || isTransactionActive()`), and throw here when refusing: nothing is written and the entities keep their events. Never dispatch in `onFlush`: rows would be written outside the flush's savepoint and survive a failed flush that the caller swallows.
- `postFlush` runs **after** the flush's SQL, before the caller's `COMMIT`, and never after a failed flush. Rescan the identity map (events recorded during the flush), store through `RecordedEventsPublisher` (`EventBusInterface::dispatch()` with `DispatchMode::OUTBOX`, `OutboxStoredStamp` checked), release once all are stored.
- Both hooks run at priority -1024, after the other listeners; `postCommitCleanup()` empties the scheduled lists right after `postFlush`, so writes scheduled while storing are compared by count and refused.

## Failure after the write

Any failure once the flush wrote (store failure, clear/close/reset during the flush, a flush/persist/remove/clear by event-bus middleware, events recorded while storing, a bus that did not store) goes through `failAfterWrite()`: `setRollbackOnly()` on the connection, `EntityManager::close()`, rethrow. Never swallow, never store part of the events. A refused flush before `BEGIN` does not close anything.

## PendingFlush state

One `PendingFlush` per entity manager (`WeakMap`), holding strong references to the collected entities (deleted ones leave the identity map), tagged with the `UnitOfWork` (an in-place reset gives a new one), the transaction level of the caller, and flags `cleared`/`storing`/`failure`. Nested flushes merge into it. It is stale — silently discarded — when its transaction ended (`isAbandoned()`) or a failed flush closed the manager and it was reset; a clear or reset while a flush with unstored events is in progress fails after write. `reset()` (`kernel.reset`) forgets everything.

## Conventions

- Only public ORM/DBAL API that exists in ORM 3.6+ and 4, DBAL 4.0+: `getIdentityMap()`, `getScheduledEntityInsertions()/Deletions()`, `isAutoCommit()`, `isTransactionActive()`, `getTransactionNestingLevel()`, `setRollbackOnly()`. Uninitialized proxies (Persistence `Proxy`, native lazy objects) are never loaded.
- `LogicException` messages name the fix; no new `@api` exception (the transaction refusal is `OutboxRequiresTransactionException` with `$entityClasses`/`$afterCommit`).
- Tests use a real `EntityManager` on `TestDatabase::connect()` (`#[Group('database')]`), entities from `tests/Fixture/Entity` via `TestEntityManager`, and assert rows, `isRollbackOnly()`, `isOpen()` and the entities' remaining events.
