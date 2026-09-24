---
paths:
  - src/Handler/**
  - src/Attribute/**
  - src/Contract/*Handler*
  - src/Contract/EnvelopeAware*
---

# Handler Implementation Contract

## Implementation Paths

**Extend the abstract base class** (preferred) — `AbstractCommandHandler`, `AbstractQueryHandler`, `AbstractEventHandler`. These provide `EnvelopeAware` support automatically via trait inclusion and enforce the setup flow via `final __invoke()`. Use this path unless the handler already extends another base class.

**Plain class with a typed `__invoke()`** — registered by the attribute alone, or by implementing the marker interface `CommandHandler` / `QueryHandler` / `EventHandler`. The interfaces declare NO methods on purpose: PHP forbids narrowing a parameter type, so a declared `__invoke(Command $command)` would make `__invoke(CreateTask $command)` a fatal error. Never add `__invoke()` to these interfaces. Optionally implement `EnvelopeAware` + use `EnvelopeAwareTrait` if envelope access is needed.

## Method Names

Each abstract handler delegates to a domain-language method:
- **Commands** → `abstract protected function handle(Command $command): mixed`
- **Queries** → `abstract protected function fetch(Query $query): mixed`
- **Events** → `abstract protected function on(Event $event): void`

These names are intentional — they describe the handler's relationship to the message type. Do not rename them.

## Attribute Declaration

Handlers declare their message with `#[AsCommandHandler]`, `#[AsQueryHandler]` or `#[AsEventHandler]` (the `command`/`query`/`event` parameter names the concrete message class), or implement the marker interface and let the message be inferred from the type of the first `__invoke()` parameter. The `bus` parameter is optional: without it the handler is registered on the sync bus of its type AND on the async bus of its type when one is configured (workers consume async messages on the async bus).

Attributes are repeatable: a single class can handle multiple message types by stacking attributes. Each attribute results in a separate `messenger.message_handler` tag.

## Discovery Requirements

`CqrsHandlerPass` infers the message type from the `__invoke()` parameter's type-hint via reflection. For discovery to work:
- Without an explicit message in the attribute, the `__invoke()` method MUST have a type-hinted first parameter; otherwise compilation fails with "Cannot determine the message handled by ...". Union members are routed individually; an intersection type is only accepted when one member implies all others
- The `handles` attribute on the handler attribute must reference a class that matches the parameter type or is a subclass of it
- Handlers discovered via marker interfaces (`CommandHandler`, `QueryHandler`, `EventHandler`) get the `somework_cqrs.handler_interface` tag, which `CqrsHandlerPass` converts to `messenger.message_handler` before Messenger's own pass runs

## EnvelopeAware Access

When extending abstract handlers, use `$this->getEnvelope()` (inherited from trait) to access stamps, metadata, or correlation IDs. `EnvelopeAwareHandlersLocator` (one decorator per bus, registered by `EnvelopeAwareHandlersLocatorPass`) calls `setEnvelope()` right before yielding the original handler descriptor — no null checks needed inside `handle()`/`fetch()`/`on()`. Never wrap handlers in closures there: Messenger identifies handlers by descriptor name, and identical names make it skip the second handler of a message.

When implementing the interface directly and needing envelope access: implement `EnvelopeAware`, use `EnvelopeAwareTrait`, and call `$this->getEnvelope()` in your `__invoke()` method.

## Template Generics

Handler interfaces use `@template` annotations for static analysis type safety. When creating concrete handlers, annotate the class with `@extends AbstractCommandHandler<ConcreteCommand>` (or `@implements CommandHandler<ConcreteCommand>`) so PHPStan can verify type consistency.

## Creating New Attribute Classes

If adding a new handler attribute (rare — the three existing ones cover standard CQRS types):
- Use `#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]` and `final class`
- First constructor parameter: `public readonly string $message` with `@param class-string<MarkerInterface>` docblock
- Second parameter: `public readonly ?string $bus = null` for optional bus override
- Register via `registerAttributeForAutoconfiguration()` in `CqrsExtension` — Symfony doesn't support attribute inheritance, so each attribute must be registered individually
- The registration closure adds a `messenger.message_handler` tag with `handles` and `bus` attributes
