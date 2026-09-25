---
paths:
  - src/Attribute/**
  - src/Contract/*Handler*
  - src/Contract/EnvelopeAware*
---

# Handler Implementation Contract

## Implementation Paths

There are no base classes (the abstract handlers were removed in 0.5: PHP cannot narrow their `handle(Command $command)` parameter).

**Plain class with a typed `__invoke()`** — registered by the attribute alone, or by implementing the marker interface `CommandHandler` / `QueryHandler` / `EventHandler`. The interfaces declare NO methods on purpose: PHP forbids narrowing a parameter type, so a declared `__invoke(Command $command)` would make `__invoke(CreateTask $command)` a fatal error. Never add `__invoke()` to these interfaces. Optionally implement `EnvelopeAware` + use `EnvelopeAwareTrait` if envelope access is needed.

## Attribute Declaration

Handlers declare their message with `#[AsCommandHandler]`, `#[AsQueryHandler]` or `#[AsEventHandler]` (the `command`/`query`/`event` parameter names the concrete message class), or implement the marker interface and let the message be inferred from the type of the first `__invoke()` parameter. The `bus` parameter is optional: without it the handler is registered on the sync bus of its type AND on the async bus of its type when one is configured (workers consume async messages on the async bus).

Attributes are repeatable: a single class can handle multiple message types by stacking attributes. Each attribute results in a separate `messenger.message_handler` tag.

## Discovery Requirements

`CqrsHandlerPass` infers the message type from the `__invoke()` parameter's type-hint via reflection. For discovery to work:
- Without an explicit message in the attribute, the `__invoke()` method MUST have a type-hinted first parameter; otherwise compilation fails with "Cannot determine the message handled by ...". Union members are routed individually; an intersection type is only accepted when one member implies all others
- The `handles` attribute on the handler attribute must reference a class that matches the parameter type or is a subclass of it
- Handlers discovered via marker interfaces (`CommandHandler`, `QueryHandler`, `EventHandler`) get the `somework_cqrs.handler_interface` tag, which `CqrsHandlerPass` converts to `messenger.message_handler` before Messenger's own pass runs

## EnvelopeAware Access

Implement `EnvelopeAware` and use `EnvelopeAwareTrait`; `$this->getEnvelope()` gives stamps, metadata or correlation IDs. `EnvelopeAwareHandlersLocator` (one decorator per bus, registered by `EnvelopeAwareHandlersLocatorPass`) calls `setEnvelope()` right before yielding the original handler descriptor — no null checks needed inside `__invoke()`. Only buses with an `EnvelopeAware` handler get the decorator. Never wrap handlers in closures there: Messenger identifies handlers by descriptor name, and identical names make it skip the second handler of a message.

## Template Generics

Handler interfaces use `@template` annotations for static analysis type safety. When creating concrete handlers, annotate the class with `@implements CommandHandler<ConcreteCommand>` so PHPStan can verify type consistency.

## Creating New Attribute Classes

If adding a new handler attribute (rare — the three existing ones cover standard CQRS types):
- Use `#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]` and `final class`
- First constructor parameter: the message class, named after the type (`public readonly string $command` / `$query` / `$event`) with a `@param class-string` docblock
- Second parameter: `public readonly ?string $bus = null` for optional bus override
- Register via `registerAttributeForAutoconfiguration()` in `CqrsExtension` — Symfony doesn't support attribute inheritance, so each attribute must be registered individually
- The registration closure adds a `messenger.message_handler` tag built by `CqrsExtension::handlerTag()`: `handles`, `bus`, the `somework_cqrs_type` marker (`CqrsHandlerPass::TYPE_ATTRIBUTE`) and, for events, `priority` and `from_transport`
