---
paths:
  - src/Support/**
  - src/Bus/**
---

# Stamp Decider Pipeline

## How It Works

`StampsDecider` aggregates `StampDecider` implementations into a per-message-class pipeline. When `AbstractMessengerBus::dispatchMessage()` calls `decide()`, the aggregator runs all applicable deciders in priority order, threading the stamp array through each stage. The pipeline is cached per message class on first invocation.

## Implementing a New Decider

Choose the right interface:
- **`MessageTypeAwareStampDecider`** — when the decider only applies to specific message types (Command, Query, Event). Implement `messageTypes()` returning the applicable contract classes. This is called once at construction, not per-dispatch. Most deciders use this.
- **`StampDecider`** — when the decider applies to all messages regardless of type (`AsynchronousStampDecider`, `CausationIdStampDecider`, `IdempotencyStampDecider`, `DispatchAfterCurrentBusStampDecider`).

The `decide(object $message, DispatchMode $mode, array $stamps): array` method receives the current stamp list and MUST return the updated list. Four patterns exist in the codebase:

1. **Append-only** — Spread new stamps onto the array: `[...$stamps, ...$newStamps]` (see `RetryPolicyStampDecider`)
2. **Conditional append** — Check if the resolver returns a stamp, append only if non-null (see `MessageSerializerStampDecider`)
3. **Early-exit-if-present** — Check if a stamp type already exists in the array, return unchanged if so (see `MessageTransportStampDecider`, `MessageMetadataStampDecider`, `DispatchAfterCurrentBusStampDecider`)
4. **Enrich the last stamp** — Replace the last stamp of a type with an enriched copy (see `CausationIdStampDecider`)

Stamps passed by the caller always win: never remove, replace or duplicate a stamp the caller supplied.

Never mutate the input array in place — always return a new array. `StampsDecider` calls `array_values()` on the final result to ensure sequential keys.

## Priority Bands

Deciders are sorted by priority via `TaggedIteratorArgument` (higher = earlier). Established bands:

| Priority | Purpose | Example |
|----------|---------|---------|
| 225 | Rate limiting (fails fast) | `RateLimitStampDecider` |
| 200 | Retry policies | `RetryPolicyStampDecider` |
| 180 | `#[Asynchronous]` | `AsynchronousStampDecider` |
| 175 | Transport routing | `MessageTransportStampDecider` |
| 150 | Serialization | `MessageSerializerStampDecider` |
| 125 | Metadata | `MessageMetadataStampDecider` |
| 110 | Event sequence | `SequenceStampDecider` |
| 100 | Causation id (needs the metadata stamp) | `CausationIdStampDecider` |
| 50 | Idempotency | `IdempotencyStampDecider` |
| 0 | Cross-cutting (runs last) | `DispatchAfterCurrentBusStampDecider` |

Place new deciders in the appropriate band. If a decider depends on stamps from an earlier stage, give it a lower priority. Cross-cutting deciders that should always run last use priority 0.

## Registration

Register new deciders in `StampsDeciderRegistrar` following the existing pattern:
1. Create a `Definition` with the decider class and its constructor arguments (typically a resolver reference from an earlier registrar)
2. Tag it with `somework_cqrs.dispatch_stamp_decider` and a `priority` attribute (message types are declared by `MessageTypeAwareStampDecider::messageTypes()`, not by the tag)
3. The `StampsDecider` aggregator collects all tagged services automatically via `TaggedIteratorArgument`

Service ID convention: `somework_cqrs.stamp_decider.{type}_{concern}` for type-aware deciders, `somework_cqrs.{concern}_stamp_decider` for generic ones.

## Third-Party Extension

Users can add custom deciders by tagging their own services with `somework_cqrs.dispatch_stamp_decider` and implementing `StampDecider`. They don't need to modify `StampsDeciderRegistrar`. The tagged iterator collects all services with this tag automatically.
