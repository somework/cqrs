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
- **`ASYNC`** — Route to the async bus. Requires `command_async` or `event_async` bus to be configured — throws `LogicException` if not. No handler result available to the caller.

Explicit mode (`SYNC`/`ASYNC`) bypasses the decider entirely. Use `DEFAULT` unless you have a specific reason to override.

## DispatchModeDecider Resolution Order

When mode is `DEFAULT`, the decider resolves to SYNC or ASYNC by checking (first match wins):
1. Exact message class in the map
2. Parent classes (walking up inheritance)
3. Interfaces (sorted by depth — more specific wins)
4. Per-type default (`commandDefault` / `eventDefault`)
5. Fallback: `SYNC` for unrecognized message types

## Return Values

**CommandBus**: `dispatchSync()` extracts the result from `HandledStamp`. Prefer void in handlers; returning server-generated metadata (IDs, timestamps) is acceptable. `dispatch()` returns the raw `Envelope` — use this when you don't need the result or when dispatching async.

**QueryBus**: `ask()` validates exactly one `HandledStamp` exists and returns its result. Zero handlers or multiple handlers both throw `LogicException`. Queries always return data.

**EventBus**: All methods return `Envelope`. Never extract handler results from events — they are fire-and-forget notifications.

## Error Propagation

- **CommandBus/QueryBus** — Exceptions propagate immediately to the caller. Failed commands mean the operation failed; failed queries mean data couldn't be retrieved.
- **EventBus** — Handler failures in async mode are handled by retry/dead-letter mechanisms, not propagated to the caller. For sync events, `AllowNoHandlerMiddleware` suppresses `NoHandlerForMessageException` specifically for `Event` instances.

## Architectural Note

`QueryBus` does NOT extend `AbstractMessengerBus` because it has fundamentally different semantics: single bus (no sync/async split), no `DispatchModeDecider`, and strict result validation. `CommandBus` and `EventBus` share `AbstractMessengerBus` because they both support sync/async routing with the same `dispatchMessage()` flow.
