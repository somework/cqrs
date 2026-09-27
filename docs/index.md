# SomeWork CQRS Bundle

Welcome to the documentation for `somework/cqrs-bundle` -- a Symfony bundle that wires Command, Query, and Event buses on top of Symfony Messenger.

The bundle auto-discovers handlers via PHP attributes, provides a composable stamp pipeline for retry policies, transport routing, serialization, and metadata, and ships with testing utilities for fast, isolated unit tests.

## Highlights

- **Three dedicated buses** -- `CommandBus`, `QueryBus`, and `EventBus` with distinct dispatch semantics, available through the `CommandBusInterface`, `QueryBusInterface`, and `EventBusInterface` interfaces.
- **Attribute-based discovery** -- Annotate handlers with `#[AsCommandHandler]`, `#[AsQueryHandler]`, or `#[AsEventHandler]`; they are registered on the synchronous bus and, when configured, the asynchronous bus of their type.
- **Sync or async per message** -- Choose per call (`DispatchMode`), per class (`#[Asynchronous]`), or in configuration.
- **Stamp pipeline** -- Composable `StampDecider` system with priority ordering. Attach retry policies, transport routing, serializer stamps, and metadata per message class or per message type.
- **Testing utilities** -- `FakeCommandBus`, `FakeQueryBus`, and `FakeEventBus`, plus `assertDispatched()` and `assertNotDispatched()` with callback-based property assertions.
- **Optional patterns** -- Transactional outbox, event ordering, idempotency, rate limiting, causation ID propagation, and OpenTelemetry tracing.

## Quick links

- [Getting Started](getting-started.md) -- install the bundle, dispatch your first command, and explore queries, events, async, and testing.
- [Usage Guide](usage.md) -- handler registration, dispatch modes, exceptions, console tooling, and common patterns.
- [Configuration Reference](reference.md) -- every `somework_cqrs` option explained.
- [Migration Guide](migration.md) -- move an existing Symfony Messenger application to the bundle.
- [Middleware & Stamp Pipeline](middleware.md) -- built-in middleware and stamp deciders, and how to add your own.
- [Testing Guide](testing.md) -- fake buses, assertions, and integration testing.
- [Production Guide](production.md) -- deployment, workers, health checks, and monitoring.
- [Troubleshooting](troubleshooting.md) -- common issues and solutions.

## Requirements

- PHP 8.2 or newer (0.7 will require PHP 8.3)
- Symfony 7.4 (7.4.9 or newer) or 8.1 and newer

Some features need optional packages: `symfony/lock` for idempotency, `symfony/rate-limiter` for rate limiting, `doctrine/dbal` 4.3+ and `doctrine/doctrine-bundle` for the transactional outbox, `open-telemetry/api` 1.8+ for tracing, and PHPUnit 11.5, 12.5 or 13 for the testing helpers.

## License

MIT. See [LICENSE](https://github.com/somework/cqrs/blob/main/LICENSE).
