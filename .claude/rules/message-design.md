# CQRS Message Design

## Structure

Messages (commands, queries, events) are immutable DTOs — data carriers with no behavior. Every message class should be:

- `final class` with constructor property promotion
- All properties `public readonly` (primitives: string, int, float, bool, array)
- No methods beyond the constructor
- Implements the appropriate marker interface (`Command`, `Query`, or `Event`)
- Carries `/** @psalm-immutable */`, and queries `@implements Query<ResultType>` (`Query<mixed>` when untyped), as `somework:cqrs:generate` writes

Keep message classes minimal (typically 4-20 lines). The class name IS the documentation — it describes the intent (command/query) or the fact (event).

## Immutability Contract

The marker interfaces carry `@psalm-immutable`. Psalm does not propagate it: it requires `@psalm-immutable` on every message class (and does not support template defaults, so declare the generics). This means:
- Properties cannot be modified after construction (enforced by both `readonly` and Psalm)
- No methods that modify state are allowed
- Any "with" methods (used only in stamps, not messages) must return new instances

This matters because messages may be serialized, logged, replayed, or dispatched asynchronously. Mutation after dispatch causes data corruption that is extremely hard to debug.

## Property Design

Use primitives, not value objects, for message properties. This keeps messages serialization-friendly across sync and async transports without custom normalizers. Convert primitives to value objects inside handlers when domain logic requires it.

Messages can implement additional marker interfaces beyond the base type for hierarchy-based configuration (e.g., `RetryAwareMessage`, `AuditLogEvent extends Event`). The resolver pattern walks these interfaces when resolving per-message config — see `resolver-pattern.md`.

## Constructor Validation

Messages generally trust their callers and have empty constructor bodies. Structural validation (non-empty IDs, format constraints) is acceptable when a structurally invalid message would cause cryptic failures downstream — see `MessageMetadataStamp` for this pattern. Business validation belongs in handlers or Symfony's validation middleware, not in the message constructor.

## Events Recorded by Entities

Entities and aggregates may record events (`RecordsEvents` + `RecordsEventsTrait::recordThat()`); the events themselves stay DTOs as above, built with the values they need at the time they are recorded. Entities never attach stamps: per-event policies come from the configuration and stamp deciders. Recorded events are **domain events** (the model's own facts); **integration events** (a published, versioned contract) are dispatched by a handler of the domain event, never recorded by entities. See `docs/domain-events.md`.

## Semantics

- **Commands** — imperative intent ("CreateTask", "ShipOrder"). Exactly one handler. May return a result via `CommandBus`.
- **Queries** — data request ("FindTask", "ListTasks"). Exactly one handler, must return a result via `QueryBus`. Zero properties is valid (e.g., `ListTasksQuery`).
- **Events** — past-tense fact ("TaskCreated", "OrderShipped"). Zero to many handlers. Fire-and-forget via `EventBus`.
