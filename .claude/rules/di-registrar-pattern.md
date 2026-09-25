---
paths:
  - src/DependencyInjection/**
---

# DI Registrar & Compiler Pass Conventions

## Registrar Pattern

Every registrar implements `register(ContainerBuilder $container, array $config): void` and is stateless — instantiated in `CqrsExtension::load()`, called once, discarded.

When adding a new registrar:
1. Create it in `src/DependencyInjection/Registration/`
2. Add the call in `CqrsExtension::load()` respecting dependency order — registrars that produce resolver references (Retry, Serializer, Metadata, Transport) MUST run before `StampsDeciderRegistrar` which consumes them
3. Use `ContainerHelper` for shared operations (`configuredService()` for a service id taken from the configuration — it records the config path for `ValidateConfiguredServicesPass` — `registerServiceAlias()`, `registerBooleanLocator()`)
4. Use `ServiceLocatorTagPass::register($container, $serviceMap)` to create lazy service locators — never inject raw `Reference` arrays for multi-variant lookups
5. Pass plain `Reference` values to `ServiceLocatorTagPass::register()`: it wraps them in `ServiceClosureArgument` itself. Wrapping them again makes the locator return closures instead of services
6. Registrars only see the bundle's own configuration: services and aliases of other bundles (e.g. `messenger.default_bus`) do not exist yet. Anything that needs them belongs in a compiler pass

## Service ID Conventions

Follow the established naming: `somework_cqrs.{concern}.{type}` for aliases, `somework_cqrs.{concern}.{type}_locator` for locators, `somework_cqrs.{concern}.{type}_resolver` for resolvers. Examples: `somework_cqrs.retry.command`, `somework_cqrs.retry.command_locator`, `somework_cqrs.retry.command_resolver`.

## Per-Message Override Hierarchy

All registrars that support message-specific config follow a 3-level resolution: message-specific map entry → per-type default (command/query/event) → global default. The registrar collapses the two defaults at compile time (`$config[$type]['default'] ?? $config['default']`) and stores the result under the resolver's `DEFAULT_KEY` in the locator, next to the map entries.

## Compiler Passes

Registered in `SomeWorkCqrsBundle::build()`. The phase is chosen by the container state each pass needs:

| Pass | Phase / priority | Why |
|------|------------------|-----|
| `ValidateBusIdsPass` | BEFORE_OPTIMIZATION, 2 | Rejects `buses.*` ids that are not Messenger buses before handlers are registered on them |
| `CqrsHandlerPass` | BEFORE_OPTIMIZATION, 1 | Normalises handler tags (message, buses) before Messenger's `MessengerPass` (priority 0) consumes them |
| `CqrsRetryStrategyPass` | BEFORE_OPTIMIZATION, 0 | Validates `retry_strategy.transports` and wires `CqrsRetryStrategy` into `messenger.retry_strategy_locator` (wrapping the transport's own strategy as fallback) |
| `OutboxRelayLockPass` | BEFORE_OPTIMIZATION, 0 | Prefixes the relay lock name with `%cache.prefix.seed%` (FrameworkBundle's parameter, unknown while the extension loads) |
| `OutboxSigningSecretPass` | BEFORE_OPTIMIZATION, 0 | Binds `%kernel.secret%` (FrameworkBundle's parameter, unknown while the extension loads) to the outbox signer unless `outbox.signing.secret` is set; fails clearly without it |
| `TransportRoutingPass` | BEFORE_OPTIMIZATION, 0 | Passes the message types routed by `framework.messenger.routing` (keys of `messenger.senders_locator`) to `MessageTransportStampDecider` |
| `ValidateConfiguredServicesPass` | BEFORE_OPTIMIZATION, 10 | Reports a missing service or rate limiter with the config path that names it (recorded by `ContainerHelper::configuredService()`), before other passes fail on the dangling reference |
| `ValidateIdempotencyDependenciesPass` | BEFORE_OPTIMIZATION, -1 | Logs why idempotency cannot deduplicate |
| `EnvelopeAwareHandlersLocatorPass`, `HealthCheckerLocatorPass`, `AllowNoHandlerMiddlewarePass`, `CausationIdMiddlewarePass`, `OpenTelemetryMiddlewarePass`, `DeduplicationLockReleasePass` | BEFORE_OPTIMIZATION, -8 | Run after `MessengerPass` built the handler locators and bus middleware lists, and before optimization so references to aliases still resolve |
| `OutboxStoragePass` | BEFORE_OPTIMIZATION, 0 | Keeps setup, failed, health and the relay's schema report on the configured storage (`somework_cqrs.outbox.base_storage`) when the application decorates `somework_cqrs.outbox.storage` (decorators are applied during optimization); checks that a custom storage implements `OutboxStorage` |
| `LoggerChannelPass` | BEFORE_OPTIMIZATION, -9 | Moves the bundle's services to the `cqrs` Monolog channel, after every pass that adds a service with a logger |
| `ValidateTransportNamesPass`, `ValidateHandlerCountPass` | BEFORE_OPTIMIZATION, 0 (default) | Validation of the collected metadata (configured transports, `#[Asynchronous(transport: ...)]` of handled messages, handler counts) |
| `RemoveHandlerMetadataParameterPass` | AFTER_REMOVING | Drops `somework_cqrs.handler_metadata` once `HandlerRegistry` received it, so it is not dumped into the main container class |

Middleware is inserted with `MessengerMiddlewareInjector`, right after Messenger's `dispatch_after_current_bus` middleware (deferred messages continue with the stack after it). Resolve bus ids with `CqrsBusIds` (aliases such as `messenger.default_bus` are only known in compiler passes).

Never register passes at TYPE_OPTIMIZE or later when they add references to aliases: alias resolution has already run and the references would dangle.

## Configuration Tree Builder

When adding a new config section that supports per-message overrides, follow the established pattern in `Configuration.php`:

1. Every section has the same shape: an optional global `default`, then per type `arrayNode($type) → addDefaultsIfNotSet() → children()` with a nullable `default` and a `map` (`messageKeyedMap()`) keyed by FQCN. Service sections use `configureServiceSection()`; the others (dispatch modes, transports, dispatch-after-current-bus, rate limiting) have their own `configure*Section()` helper with the same keys
2. Iterate `self::TYPES` (command, query, event) — and the async variants where applicable. `dispatch_modes` and `transports` have no global default: it would also hit synchronous messages
3. Use `scalarPrototype()` for service IDs, `booleanPrototype()` for flags, `arrayPrototype()` for lists
4. Add `beforeNormalization` when accepting both scalar and array inputs (see transport section). When an option moves, add a `beforeNormalization` that throws `"somework_cqrs.<old>" moved to "somework_cqrs.<new>".` (see `rejectMovedOptions()`); there are no deprecation layers in 0.x
5. Add `.info()` descriptions using `sprintf()` with the `$type` parameter for consistent documentation

The resulting config array structure (`$config[$section][$type]['default']` and `$config[$section][$type]['map']`) is passed directly to the corresponding registrar's `register()` method.

## Key Constraints

- Never call `$container->get()` in a compiler pass or registrar — only work with definitions and references
- Guard with `$container->has()` / `$container->hasDefinition()` before accessing services that may not exist
- Store cross-phase data in container parameters (e.g., `somework_cqrs.handler_metadata`, `somework_cqrs.transport_names`)
- Validate configuration in `Configuration` (service ids with `requireName()`, per-message maps with `messageKeyedMap()`), and structural `enabled` flags in `CqrsExtension::assertCompileTimeFlags()`
