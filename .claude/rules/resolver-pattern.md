---
paths:
  - src/Support/**
---

# Message Type Resolver Pattern

## Architecture

`AbstractMessageTypeResolver` + `MessageTypeLocator` implement hierarchy-aware service resolution. The resolution order is deterministic and intentionally more precise than Symfony's flat lookup:

1. Exact message class match
2. Parent classes (walking up via `get_parent_class()`)
3. Interfaces on the concrete class, then interfaces on parents (depth-first, deduplicated via `$seen`)
4. Type default / global default (handled in `resolveFallback()`)

## Creating a New Resolver

Extend `AbstractMessageTypeResolver` and implement two hooks:

- **`assertService(string $key, mixed $service): mixed`** — Validate the resolved service matches your expected type. Handle `Closure` invocation here if the registrar wraps services in closures. Throw `LogicException` with `get_debug_type()` for clear diagnostics.
- **`resolveFallback(object $message): mixed`** — Provide the default when no hierarchy match is found. Two variants exist in the codebase:
  - **Simple:** Return a stored default (see `RetryPolicyResolver`)
  - **Two-level chain:** Try `TYPE_DEFAULT_KEY`, then `GLOBAL_DEFAULT_KEY` using `resolveFirstAvailable()` (see `MessageSerializerResolver`, `MessageMetadataProviderResolver`)

Choose the simple variant unless the config tree supports both per-type and global defaults.

## WeakMap Caching

`MessageTypeLocator` uses a static `WeakMap<ContainerInterface, ...>` so cache lifetime is tied to the container — automatic cleanup on kernel reboot or test teardown, no manual invalidation needed.

Cache key structure: `container → messageClass → ignoredSignature`. The ignored signature is computed from sorted, deduped, null-byte-joined key names. When adding a new resolver that needs to exclude keys from hierarchy walk (like serializer/metadata exclude their default keys), pass them as `$ignoredKeys` to `resolveService()` — the cache handles this automatically.

Do not use instance-level array caches for hierarchy resolution — use the shared `MessageTypeLocator` WeakMap. Exception: `MessageTransportResolver` uses an instance array because it intentionally skips caching dynamic closures while caching static values.

## MessageTransportResolver Exception

`MessageTransportResolver` does NOT extend `AbstractMessageTypeResolver` because it needs:
- Nullable return type (`?array` vs always-resolved service)
- Selective caching: static sources cached, closures re-invoked each call
- Complex normalization logic (strings, arrays, Traversables, Closures with optional container parameter)

If your new resolver needs nullable returns or dynamic sources, follow `MessageTransportResolver`'s standalone pattern. Otherwise, extend the abstract base.

## Corresponding Registrar

Every resolver needs a registrar in `src/DependencyInjection/Registration/` that builds its service locator from config. See `di-registrar-pattern.md` for registrar conventions.
