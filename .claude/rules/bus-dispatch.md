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
- **`SYNC`** — Dispatch on the sync bus. It only selects the bus: the handlers run in the current process unless `transports.<type>` (the sync transports of the bundle) or Messenger's routing (`framework.messenger.routing`, `#[AsMessage(transport:)]`) sends the message to a transport — `dispatch()` then silently returns the envelope with a `SentStamp`, while `dispatchSync()`/`ask()` throw `MessageSentToTransportException`. Use `dispatchSync()`/`ask()` when the caller needs the result.
- **`ASYNC`** — Route to the async bus. Requires `command_async` or `event_async` bus to be configured — throws `AsyncBusNotConfiguredException` if not. No handler result available to the caller. Without a transport (`transports.<type>_async`, `#[Asynchronous(transport:)]`, the `async` fallback of a bare `#[Asynchronous]`, or Messenger's routing) Messenger handles the message right away on the async bus, in the calling process, and `MessageTransportStampDecider` logs a warning.
- **`OUTBOX`** — Check the transaction (before any side effect), run the stamp pipeline as for `ASYNC`, then dispatch on the async bus (the sync bus without one) with an internal `StoreInOutboxStamp`. `OutboxPrepareMiddleware` (right after `add_default_stamps_middleware`) moves the `DeduplicateStamp`s into it (Messenger's deduplication must not lock yet) and drops `DispatchAfterCurrentBusStamp`s. The application's middleware runs (Doctrine's `doctrine_transaction`/`doctrine_open_transaction_logger` are skipped through `OutboxBypassMiddleware`); `OutboxStoreMiddleware`, right before `send_message`, stores the envelope with `OutboxWriter::storeEnvelope()` (one row per transport, never deferred) instead of sending it; the returned envelope carries `OutboxStoredStamp`. Requires the outbox (`OutboxNotConfiguredException`) and, with `outbox.require_transaction`, an open transaction on the outbox connection. The relay dispatches the stored envelope (without the internal stamp) on the same Messenger bus, never through the CQRS buses, so a stored message is not stored again; its `RelayedFromOutboxStamp` (`@api`: middleware checking the dispatching context skips it, as it skips `ReceivedStamp`) makes `OutboxStoreMiddleware` drop stamps middleware adds again for classes the stored envelope carries (the caller's context wins).

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

**Synchronous results** (`dispatchSync()`, `ask()`) go through `SynchronousResult`: it strips the `DispatchAfterCurrentBusStamp`s passed by the caller (the stamp pipeline adds one only to asynchronous dispatches; one from the message's default stamps or a middleware is not removed), throws `MessageSentToTransportException` when the message was sent to a transport, `DuplicateMessageException` when deduplication dropped it, and rethrows the single cause of a `HandlerFailedException`. Both `dispatchSync()` and `ask()` throw `MultipleHandlersException` when more than one handler ran (the result would be ambiguous).

**EventBus**: All methods return `Envelope`. Never extract handler results from events — they are fire-and-forget notifications.

## Error Propagation

- **CommandBus/QueryBus** — Exceptions propagate immediately to the caller. `dispatchSync()` and `ask()` rethrow the handler's own exception when exactly one handler failed; `dispatch()` (also in sync mode) surfaces Messenger's `HandlerFailedException`. Failed commands mean the operation failed; failed queries mean data couldn't be retrieved.
- **EventBus** — Handler failures of an event sent to a transport are handled by the worker's retry/dead-letter mechanisms, not propagated to the caller. An `ASYNC` dispatch without a transport runs the handlers in the calling process (see `ASYNC` above), and their failures reach the caller as `HandlerFailedException` (`DelayedMessageHandlingException` when it was deferred until the current handler finished), as for a synchronous dispatch. `AllowNoHandlerMiddleware` suppresses `NoHandlerForMessageException` for `Event` instances on the event buses; an event a worker received without a handler on its bus is acknowledged, with a warning log when the event has handlers elsewhere (another bus, or `fromTransport`).

## Caller Stamps

Stamps passed to `dispatch()`/`ask()` win over the stamp pipeline: deciders never replace or duplicate a stamp the caller supplied (metadata, serializer, sequence, deduplicate, dispatch-after-current-bus).

## Architectural Note

`QueryBus` does NOT extend `AbstractMessengerBus` because it has fundamentally different semantics: single bus (no sync/async split), no `DispatchModeDecider`, and strict result validation. `CommandBus` and `EventBus` share `AbstractMessengerBus` because they both support sync/async routing with the same `dispatchMessage()` flow.
