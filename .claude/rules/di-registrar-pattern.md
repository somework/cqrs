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
3. Use `ContainerHelper` for shared operations (`ensureServiceExists()`, `registerServiceAlias()`, `registerBooleanLocator()`)
4. Use `ServiceLocatorTagPass::register($container, $serviceMap)` to create lazy service locators — never inject raw `Reference` arrays for multi-variant lookups
5. Wrap locator entries in `ServiceClosureArgument` for lazy loading

## Service ID Conventions

Follow the established naming: `somework_cqrs.{concern}.{type}` for aliases, `somework_cqrs.{concern}.{type}_locator` for locators, `somework_cqrs.{concern}.{type}_resolver` for resolvers. Examples: `somework_cqrs.retry.command`, `somework_cqrs.retry.command_locator`, `somework_cqrs.retry.command_resolver`.

## Per-Message Override Hierarchy

All registrars that support message-specific config follow a 3-level resolution: message-specific map entry → per-type default (command/query/event) → global default. Build service maps that include all levels so the corresponding Resolver can walk the chain at runtime.

## Compiler Passes

Three passes registered in `SomeWorkCqrsBundle::build()` at different phases because each needs different container state:

| Pass | Phase | Priority | Why this phase |
|------|-------|----------|----------------|
| `CqrsHandlerPass` | BEFORE_OPTIMIZATION | 1 | Must normalize handler tags before Messenger's pass (priority 0) processes them |
| `AllowNoHandlerMiddlewarePass` | OPTIMIZE | 0 | Needs resolved bus definitions to inject middleware |
| `ValidateTransportNamesPass` | AFTER_REMOVING | 0 | Final validation after unused services are removed |

When adding a new compiler pass, choose the phase based on what container state it needs — never use BEFORE_OPTIMIZATION for validation that depends on resolved definitions.

## Configuration Tree Builder

When adding a new config section that supports per-message overrides, follow the established pattern in `Configuration.php`:

1. Create a private `configure*Section(NodeBuilder $parent, string $type)` helper method
2. Inside: `arrayNode($type) → addDefaultsIfNotSet() → children()` containing:
   - `scalarNode('default')` — the fallback service ID (or `enumNode`/`booleanNode` for non-service configs)
   - `arrayNode('map') → useAttributeAsKey('message')` — per-message overrides keyed by FQCN
3. Call the helper for each message type (command, query, event) — and async variants if applicable
4. Use `scalarPrototype()` for service IDs, `booleanPrototype()` for flags, `arrayPrototype()` for lists
5. Add `beforeNormalization` when accepting both scalar and array inputs (see transport section)
6. Add `.info()` descriptions using `sprintf()` with the `$type` parameter for consistent documentation

The resulting config array structure (`$config[$section][$type]['default']` and `$config[$section][$type]['map']`) is passed directly to the corresponding registrar's `register()` method.

## Key Constraints

- Never call `$container->get()` in a compiler pass or registrar — only work with definitions and references
- Guard with `$container->has()` / `$container->hasDefinition()` before accessing services that may not exist
- Store cross-phase data in container parameters (e.g., `somework_cqrs.handler_metadata`, `somework_cqrs.transport_names`)
