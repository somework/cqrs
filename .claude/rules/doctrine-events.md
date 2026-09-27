---
paths:
  - src/Doctrine/**
  - src/Outbox/RecordedEventsPublisher.php
  - src/Contract/RecordsEvents*
---

# Domain Events Recorded by Entities

`somework_cqrs.doctrine_events` stores the events that entities (`RecordsEvents`) record in the outbox when their entity manager flushes. The guarantee: an event is stored **if and only if** the change that recorded it commits. Every path that cannot guarantee this throws.

## Flush timeline (Doctrine ORM 3/4 `UnitOfWork::commit()`)

- `preFlush` (priority -1024, last) runs **before** `computeChangeSets()`: refuse here (connection `outbox.connection`, transaction `!isAutoCommit() || isTransactionActive()`), so a refused flush leaves the unit of work untouched and can be retried. `computeChangeSets()` overwrites the original data of the entities: a refusal after it (in `onFlush`, for entities only it found, e.g. by cascade) must close the entity manager, or a retry writes only part of the changes.
- `onFlush` (priority -1024, last) runs **before** the flush's `BEGIN`: collect the entities with events (scheduled insertions + identity map) and the scheduled deletions of recording classes (they leave the identity map and may record in `#[ORM\PostRemove]`). Never dispatch in `onFlush`: rows would be written outside the flush's savepoint and survive a failed flush that the caller swallows.
- `postFlush` (`PHP_INT_MAX`, first) runs **after** the flush's SQL, before the caller's `COMMIT`, and never after a failed flush. Rescan the identity map (events recorded during the flush), store through `RecordedEventsPublisher` (`EventBusInterface::dispatch()` with `DispatchMode::OUTBOX`, `OutboxStoredStamp` checked), release once all are stored and checked. Running first, no other listener can skip it by failing, clearing or flushing again. Throwing from `postFlush` skips Doctrine's `postCommitCleanup()`: always close the entity manager when it throws (the `afterCommit` refusal included). `postCommitCleanup()` empties the scheduled lists right after the listeners, so writes scheduled while storing are compared by count and refused.

## Failure after the write

Any failure once the flush wrote (store failure, clear/close/reset during the flush, a flush/persist/remove/clear by event-bus middleware, events recorded while storing, a bus that did not store) goes through `failAfterWrite()`: `setRollbackOnly()` on the connection, `EntityManager::close()`, rethrow. Never swallow, never store part of the events. A refusal in `preFlush` closes nothing.

## PendingFlush state

One `PendingFlush` per entity manager (`WeakMap`) between `onFlush` and `postFlush`, holding strong references to the collected entities (deleted ones leave the identity map), tagged with the `UnitOfWork` (an in-place reset gives a new one) and flags `cleared`/`storing`/`failure`. A clear or reset between `onFlush` and the store fails after write when the entities have events. `preFlush` drops what a flush that never reached `postFlush` left (it failed: the entities still in the unit of work are collected again), and refuses a flush during the store (`storing`). `reset()` (`kernel.reset`) forgets everything.

## Conventions

- Only public ORM/DBAL API that exists in ORM 3.6+ and 4, DBAL 4.0+: `getIdentityMap()`, `getScheduledEntityInsertions()/Deletions()`, `isAutoCommit()`, `isTransactionActive()`, `setRollbackOnly()`. Uninitialized proxies (Persistence `Proxy`, native lazy objects) are never loaded.
- `LogicException` messages name the fix; no new `@api` exception (the transaction refusal is `OutboxRequiresTransactionException` with `$entityClasses`/`$afterCommit`).
- Tests use a real `EntityManager` on `TestDatabase::connect()` (`#[Group('database')]`), entities from `tests/Fixture/Entity` via `TestEntityManager`, and assert rows, `isRollbackOnly()`, `isOpen()` and the entities' remaining events.
