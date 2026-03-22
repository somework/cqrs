---
paths:
  - src/Handler/**
  - src/Attribute/**
  - src/Contract/*Handler*
  - src/Contract/EnvelopeAware*
---

# Handler Implementation Contract

## Two Implementation Paths

**Extend the abstract base class** (preferred) — `AbstractCommandHandler`, `AbstractQueryHandler`, `AbstractEventHandler`. These provide `EnvelopeAware` support automatically via trait inclusion and enforce the setup flow via `final __invoke()`. Use this path unless the handler already extends another base class.

**Implement the interface directly** — `CommandHandler`, `QueryHandler`, `EventHandler`. Use this when the handler must extend a different base class. You must manually implement `__invoke()` with the correct parameter type-hint, and optionally implement `EnvelopeAware` + use `EnvelopeAwareTrait` if envelope access is needed.

## Method Names

Each abstract handler delegates to a domain-language method:
- **Commands** → `abstract protected function handle(Command $command): mixed`
- **Queries** → `abstract protected function fetch(Query $query): mixed`
- **Events** → `abstract protected function on(Event $event): void`

These names are intentional — they describe the handler's relationship to the message type. Do not rename them.

## Attribute Declaration

Every handler needs a `#[AsCommandHandler]`, `#[AsQueryHandler]`, or `#[AsEventHandler]` attribute with the `command`/`query`/`event` parameter specifying the concrete message class. The `bus` parameter is optional — defaults come from bundle config.

Attributes are repeatable: a single class can handle multiple message types by stacking attributes. Each attribute results in a separate `messenger.message_handler` tag.

## Discovery Requirements

`CqrsHandlerPass` infers the message type from the `__invoke()` parameter's type-hint via reflection. For discovery to work:
- The `__invoke()` method MUST exist with a type-hinted first parameter that implements `Command`, `Query`, or `Event`
- The `handles` attribute on the handler attribute must reference a class that matches the parameter type or is a subclass of it
- Handlers discovered via marker interfaces (`CommandHandler`, `QueryHandler`, `EventHandler`) get the `somework_cqrs.handler_interface` tag, which `CqrsHandlerPass` converts to `messenger.message_handler` before Messenger's own pass runs

## EnvelopeAware Access

When extending abstract handlers, use `$this->getEnvelope()` (inherited from trait) to access stamps, metadata, or correlation IDs. The envelope is injected by `EnvelopeAwareHandlersLocator` before `__invoke()` runs — no null checks needed inside `handle()`/`fetch()`/`on()`.

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
