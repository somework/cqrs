# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [3.0.0] - 2026-03-22
### Added
- Health check console command (`somework:cqrs:health`) with pluggable checkers and exit codes 0/1/2
- Event ordering via `SequenceAware` interface and `AggregateSequenceStamp` with auto-attaching stamp decider
- Rate limiting via `RateLimitStampDecider` bridging per-message-type throttling to Symfony `RateLimiterFactory`
- Transactional outbox with `DbalOutboxStorage`, relay command (`somework:cqrs:outbox:relay`), and schema subscriber
- Documentation: event-ordering.md, rate-limiting.md, outbox.md
- UPGRADE.md v3.0 migration section

## [2.1.0] - 2026-03-22
### Added
- `RetryConfiguration` interface and `CqrsRetryStrategy` bridging per-message retry config to Symfony transport retry
- `IdempotencyStampDecider` converting `IdempotencyStamp` to Symfony `DeduplicateStamp` with TTL config
- `CausationIdMiddleware` configurability: enable/disable toggle and per-bus scoping
- Documentation: retry.md, idempotency.md, middleware configuration docs
- UPGRADE.md v2.1 migration section

### Changed
- `ExponentialBackoffRetryPolicy::getStamps()` returns empty array; retry delays now handled at transport level via `CqrsRetryStrategy`

## [2.0.0] - 2026-03-22
### Added
- PSR-3 logging across buses, stamp deciders, and resolvers
- Custom exceptions: `NoHandlerException`, `MultipleHandlersException`, `AsyncBusNotConfiguredException` with FQCN and bus context
- `FakeCommandBus`, `FakeQueryBus`, `FakeEventBus` test doubles for recording dispatches
- `CqrsTestCase` abstract PHPUnit test case with CQRS assertion helpers
- `CqrsAssertionsTrait` with `assertDispatched()` and `assertNotDispatched()`
- `DispatchedMessage` PHPUnit constraint for custom assertions
- Compile-time handler count validation via `ValidateHandlerCountPass` (exactly one handler for commands/queries)
- `CausationIdMiddleware` and `CausationIdContext` for causation ID propagation
- `IdempotencyStamp` for message deduplication convention
- `ExponentialBackoffRetryPolicy` as a named DI service
- `@api`/`@internal` annotations on all public types
- UPGRADE.md with backward compatibility promise
- Complete documentation suite: Quick Start, testing guide, production guide, troubleshooting

## [1.0.0] - 2026-03-21
### Added
- Three typed bus facades: `CommandBus`, `QueryBus`, `EventBus` extending `AbstractMessengerBus`
- Configurable stamp pipeline with five built-in deciders: retry, transport, serializer, metadata, dispatch-after-current-bus
- PHP attributes for handler auto-registration: `#[AsCommandHandler]`, `#[AsQueryHandler]`, `#[AsEventHandler]`
- Sync/async dispatch via `DispatchMode` enum for commands and events
- `EnvelopeAware` interface for handlers needing access to the Messenger envelope
- Per-message config resolution walking class hierarchy and interfaces (exact match, parent classes, interfaces, type default, global default)
- Console commands: `somework:cqrs:list`, `somework:cqrs:generate`, `somework:cqrs:debug-transports`
- `HandlerRegistry` providing read-only access to compiled handler metadata via `HandlerDescriptor` DTOs
- `AllowNoHandlerMiddleware` suppressing `NoHandlerForMessageException` for events
- `ValidateTransportNamesPass` compiler pass for transport reference validation
- Marker interfaces: `Command`, `Query`, `Event`, `CommandHandler`, `QueryHandler`, `EventHandler`
- Policy contracts: `MessageNamingStrategy`, `RetryPolicy`, `MessageSerializer`, `MessageMetadataProvider`

### Removed
- Symfony 6.4 support (requires Symfony ^7.2)

## [0.1.0] - 2024-01-01
### Added
- Command, query, and event buses wired to Symfony Messenger with automatic handler discovery
- Metadata stamps and providers to append correlation and context details to dispatched messages
- Async bus configuration helpers to route commands and events through worker transports
- Console tooling for listing registered handlers and generating message skeletons
- A generator that scaffolds commands, queries, events, and matching handlers
- Foundational test suite covering registry discovery, console tooling, and service configuration

[Unreleased]: https://github.com/somework/cqrs/compare/v3.0.0...HEAD
[3.0.0]: https://github.com/somework/cqrs/compare/v2.1.0...v3.0.0
[2.1.0]: https://github.com/somework/cqrs/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/somework/cqrs/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/somework/cqrs/compare/v0.1.0...v1.0.0
[0.1.0]: https://github.com/somework/cqrs/releases/tag/v0.1.0
