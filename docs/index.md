# SomeWork CQRS Bundle

Welcome to the documentation for `somework/cqrs-bundle` -- a Symfony bundle that wires Command, Query, and Event buses on top of Symfony Messenger.

The bundle auto-discovers handlers via PHP attributes, provides a composable stamp pipeline for retry policies, transport routing, serialization, and metadata, and ships with testing utilities for fast, isolated unit tests.

## Highlights

- **Three dedicated buses** -- `CommandBus`, `QueryBus`, and `EventBus` with distinct dispatch semantics and type-safe interfaces.
- **Attribute-based discovery** -- Annotate handlers with `#[AsCommandHandler]`, `#[AsQueryHandler]`, or `#[AsEventHandler]` and skip the YAML boilerplate.
- **Stamp pipeline** -- Composable `StampDecider` system with priority ordering. Attach retry policies, transport routing, serializer stamps, and metadata per message class or per message type.
- **Testing utilities** -- `FakeCommandBus`, `FakeQueryBus`, `FakeEventBus` with `assertDispatched()` and callback-based property assertions.
- **Production patterns** -- Transactional outbox, event ordering, idempotency, rate limiting, causation ID propagation, and OpenTelemetry tracing.

## Quick links

- [Getting Started](getting-started.md) -- install the bundle, dispatch your first command, and explore queries, events, async, and testing.
- [Usage Guide](usage.md) -- handler registration, dispatch modes, console tooling, and common patterns.
- [Configuration Reference](reference.md) -- every `somework_cqrs` option explained.
- [Testing Guide](testing.md) -- FakeBus implementations, assertions, and integration testing.
- [Production Guide](production.md) -- deployment, workers, health checks, and monitoring.
- [Troubleshooting](troubleshooting.md) -- common issues and solutions.

## Requirements

- PHP 8.2 or newer
- Symfony 7.2 or newer

## License

MIT. See [LICENSE](https://github.com/somework/cqrs/blob/main/LICENSE).
