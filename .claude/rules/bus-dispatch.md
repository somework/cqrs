---
paths:
  - src/Bus/**
---

# Bus Dispatch Conventions

## Three Buses, Three Semantics

| | CommandBus | QueryBus | EventBus |
|---|---|---|---|
| Handler count | Exactly 1 | Exactly 1 | 0 to many |
| Returns result | `dispatchSync()` returns handler result; `dispatch()` returns Envelope | `ask()` always returns result | Always returns Envelope (fire-and-forget) |
| Sync/async | Both via `DispatchMode` | Always sync | Both via `DispatchMode` |
| Missing handler | Exception | Exception | Tolerated silently |

## DispatchMode Semantics

- **`DEFAULT`** — Let `DispatchModeDecider` decide based on message class, hierarchy, and per-type defaults. This is the standard path.
- **`SYNC`** — Execute handler in the current process. Use when the caller needs the result immediately.
- **`ASYNC`** — Route to the async bus. Requires `command_async` or `event_async` bus to be configured — throws `AsyncBusNotConfiguredException` if not. No handler result available to the caller.
- **`OUTBOX`** — Check the transaction (before any side effect), run the stamp pipeline as for `ASYNC`, then dispatch on the async bus (the sync bus without one) with an internal `StoreInOutboxStamp` (carrying the `DeduplicateStamp`s, which Messenger's deduplication must not see yet). The application's middleware runs; `OutboxStoreMiddleware`, right before `send_message`, stores the envelope with `OutboxWriter::storeEnvelope()` (one row per transport, never deferred) instead of sending it; the returned envelope carries `OutboxStoredStamp`. Requires the outbox (`OutboxNotConfiguredException`) and, with `outbox.require_transaction`, an open transaction on the outbox connection. The relay dispatches the stored envelope (without the internal stamp) on the same Messenger bus, never through the CQRS buses, so a stored message is not stored again.

Explicit mode (`SYNC`/`ASYNC`/`OUTBOX`) bypasses the decider entirely. Use `DEFAULT` unless you have a specific reason to override.

## DispatchModeDecider Resolution Order

When mode is `DEFAULT`, the decider resolves to SYNC, ASYNC or OUTBOX by checking (first match wins):
1. Exact message class entry in the `dispatch_modes.<type>.map`
2. `#[Outbox]` or `#[Asynchronous]` attribute on the message class (both on one class fail the build)
3. Map entries for parent classes (walking up inheritance), then interfaces
4. Per-type default (`dispatch_modes.<type>.default`)
5. Fallback: `SYNC` for unrecognized message types

## Return Values

**CommandBus**: `dispatchSync()` extracts the result from `HandledStamp`. Prefer void in handlers; returning server-generated metadata (IDs, timestamps) is acceptable. `dispatch()` returns the raw `Envelope` — use this when you don't need the result or when dispatching async.

**QueryBus**: `ask()` validates exactly one `HandledStamp` exists and returns its result. Zero handlers throw `NoHandlerException`, several `MultipleHandlersException`. Queries always return data.

**Synchronous results** (`dispatchSync()`, `ask()`) go through `SynchronousResult`: it strips `DispatchAfterCurrentBusStamp`, throws `MessageSentToTransportException` when the message was sent to a transport, `DuplicateMessageException` when deduplication dropped it, and rethrows the single cause of a `HandlerFailedException`. Both `dispatchSync()` and `ask()` throw `MultipleHandlersException` when more than one handler ran (the result would be ambiguous).

**EventBus**: All methods return `Envelope`. Never extract handler results from events — they are fire-and-forget notifications.

## Error Propagation

- **CommandBus/QueryBus** — Exceptions propagate immediately to the caller. `dispatchSync()` and `ask()` rethrow the handler's own exception when exactly one handler failed; `dispatch()` (also in sync mode) surfaces Messenger's `HandlerFailedException`. Failed commands mean the operation failed; failed queries mean data couldn't be retrieved.
- **EventBus** — Handler failures in async mode are handled by retry/dead-letter mechanisms, not propagated to the caller. `AllowNoHandlerMiddleware` suppresses `NoHandlerForMessageException` for `Event` instances on the event buses; an event a worker received without a handler on its bus is acknowledged, with a warning log when the event has handlers elsewhere (another bus, or `fromTransport`).

## Caller Stamps

Stamps passed to `dispatch()`/`ask()` win over the stamp pipeline: deciders never replace or duplicate a stamp the caller supplied (metadata, serializer, sequence, deduplicate, dispatch-after-current-bus).

## Architectural Note

`QueryBus` does NOT extend `AbstractMessengerBus` because it has fundamentally different semantics: single bus (no sync/async split), no `DispatchModeDecider`, and strict result validation. `CommandBus` and `EventBus` share `AbstractMessengerBus` because they both support sync/async routing with the same `dispatchMessage()` flow.
