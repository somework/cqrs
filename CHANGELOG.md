# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the major version is 0, minor releases may contain breaking changes; they are listed under "Changed" and explained in [UPGRADE.md](UPGRADE.md).

## [Unreleased]

Planned as 0.5.0. See [UPGRADE.md](UPGRADE.md#upgrading-from-040-to-050) for every behaviour change.

### Added
- Symfony 8 support (`^7.2 || ^8.0`).
- `MessageSentToTransportException` and `DuplicateMessageException` for `CommandBus::dispatchSync()` and `QueryBus::ask()`.
- `TraceContextStamp`: W3C trace context propagated from the dispatching process to the worker.
- `DeduplicationLockReleaseMiddleware`: a failed synchronous dispatch releases its idempotency lock.
- Outbox: `OutboxMessage::fromEnvelope()` (time-ordered UUIDv7 ids), `OutboxStorage::purgePublished()`, an `$offset` for `fetchUnpublished()`,
  the `somework:cqrs:outbox:setup` and `somework:cqrs:outbox:purge` commands, and the `outbox.connection`, `outbox.serializer` and `outbox.auto_setup` options.
- The outbox relay runs as a single instance when symfony/lock is installed (lock scoped to the project, connection and table, extended after every row) and stops after 5 consecutive send failures.
- Configuration validation: service ids must be non-empty strings, and per-message map keys must be existing classes or interfaces (a leading `\` is allowed).
- `HealthChecker`, `CheckResult` and `CheckSeverity`, as well as `OutboxMessage` and `OutboxStorage`, are part of the public API (`@api`).
- The container compilation log explains why idempotency cannot deduplicate (missing symfony/lock, or Messenger's deduplicate middleware not registered).

### Changed
- Handlers without an explicit `bus` are registered on the sync bus of their type and on its async bus when one is configured.
- `CommandHandler`, `QueryHandler` and `EventHandler` are pure marker interfaces without `__invoke()`, so handlers can type-hint the concrete message.
- `#[Asynchronous]` sends messages dispatched with `DispatchMode::DEFAULT` to the async bus. Resolution order: exact `dispatch_modes` map entry, then the attribute, then parent class/interface map entries, then the default.
- Stamps passed by the caller take precedence over the stamp pipeline: `MessageMetadataStamp`, `SerializerStamp`, `AggregateSequenceStamp`, `DeduplicateStamp` and `DispatchAfterCurrentBusStamp` are no longer replaced or duplicated. The causation id is added to the last metadata stamp and an explicit causation id is kept.
- `IdempotencyStamp` stays on the envelope next to the `DeduplicateStamp` it produces.
- `dispatchSync()` and `ask()` rethrow the exception of the single failing handler instead of `HandlerFailedException`, raise `NoHandlerException` instead of Messenger's `NoHandlerForMessageException`, and ignore `DispatchAfterCurrentBusStamp`.
- `#[Asynchronous]` only chooses a transport when the configuration chooses none.
- Per-message maps resolve interfaces most specific first, independent of the declaration order.
- Retry policy stamps no longer override stamps passed by the caller.
- The bundle middleware runs right after Messenger's `dispatch_after_current_bus` middleware, so deferred messages pass through it too.
- OpenTelemetry: one span per pass, `cqrs.dispatch <Message>` (PRODUCER) when dispatching and `cqrs.consume <Message>` (CONSUMER) in the worker; deferred messages keep the trace they were dispatched in.
- `CqrsRetryStrategy` falls back to Messenger's `MultiplierRetryStrategy` defaults instead of retrying forever; delays are capped before and after jitter.
- `retry_strategy.transports` keys are kept as written and must name existing transports.
- `causation_id.buses` entries must name existing buses.
- The `enabled` flags of `outbox`, `idempotency`, `causation_id`, `sequence` and `rate_limiting` no longer accept environment variables.
- Rate limiting stays inactive until a limiter is mapped; mapping one without symfony/rate-limiter is a configuration error.
- `ValidateHandlerCountPass` checks commands and queries per bus and counts distinct services; a handler registered without a bus (e.g. a plain `#[AsMessageHandler]`) counts on every bus.
- A handler attribute whose type contradicts the message (`#[AsCommandHandler]` for an event) is a compile error.
- The bundle middleware is only added to the default bus when a facade falls back to it.
- The outbox never creates its table inside an open transaction and stores dates in UTC; the relay dispatches each message on the bus of its type (the async bus when configured), honours the stored transport name, skips undecodable rows and exits with 1 when a row failed.
- `somework:cqrs:outbox:purge --older-than` accepts only `<number> <unit>`; `outbox.table_name` must be a plain or schema-qualified identifier.
- `somework:cqrs:health` instantiates every CQRS handler and every Messenger transport.
- `somework:cqrs:generate` follows the PSR-4 mapping of the project's `composer.json`, resolves `--dir` against the project directory, validates class names, generates attribute-based handlers with a typed `__invoke()` and exits with 2 on invalid input.
- `somework:cqrs:list` exits with 2 for an unknown `--type`.
- `RateLimitResolver` accepts any `RateLimiterFactoryInterface`, including compound limiters.
- `psr/container`, `symfony/filesystem` and `symfony/service-contracts` are direct dependencies.

### Fixed
- The default installation (no bundle configuration, no symfony/rate-limiter) failed to compile.
- Bus aliases such as the default `messenger.default_bus` broke handler registration and envelope injection.
- Handlers for async messages were missing on the async bus, so workers failed with "No handler for message".
- Per-message retry policies, `dispatch_after_current_bus` overrides and rate-limiter maps failed or were ignored because their service locators were wrapped twice.
- The second envelope-aware handler (every `Abstract*Handler`) of the same message on a bus was skipped, and `HandledStamp` handler names were wrong.
- Stamp deciders were registered twice; `idempotency.enabled: false` had no effect.
- Handlers implementing a handler interface with a typed `__invoke()` caused a PHP fatal error.
- Union types dropped non-CQRS members; unroutable intersection types and interface handlers without a resolvable message now fail with a clear message.
- Option-less handler tags (e.g. from `BatchHandlerInterface` autoconfiguration) were turned into unrestricted registrations; a method-level `#[AsMessageHandler]` hid the marker-interface registration of `__invoke()`; abstract services implementing a handler interface broke the build.
- Envelope-aware handlers failed on buses that are not configured as CQRS buses; `idempotency.ttl` from an environment variable became 0; on Symfony 8.1 the bundle middleware ran before Messenger decoded failed messages.
- The internal handler type marker leaked into Messenger's handler options (`debug:messenger`).
- `dispatchSync()` and `ask()` reported a misleading `NoHandlerException` when the message was sent to a transport or deduplicated.
- The container could not be compiled when an OpenTelemetry tracer provider was registered; exceptions were recorded twice on spans.
- The idempotency lock stayed held for the whole TTL after a failed synchronous dispatch; a failing lock release no longer hides the handler's exception. The compilation log warns when the lock store (flock, semaphore, in-memory) cannot deduplicate.
- A nested dispatch handled by the same envelope-aware handler service left the outer invocation with the inner envelope.
- A failed async dispatch without an async bus consumed a rate-limiter token.
- `CausationIdContext::pop()` threw on an empty stack.
- The outbox committed or aborted the caller's transaction when it created its table, published messages twice under concurrent relays, stalled on a failing row, ordered messages randomly within the same second, stored dates without DBAL type conversion or time zone, generated index names longer than 63 characters and, on Symfony 8, marked undecodable rows as published.
- Relayed events and commands were dispatched on the default bus, so workers of multi-bus setups found no handler for them.
- The ORM schema listener added the outbox table to the schema of every connection.
- `somework:cqrs:health` reported every handler and transport as CRITICAL.
- `somework:cqrs:generate` wrote files outside the PSR-4 layout, accepted `..` and invalid class names, could escape the project directory through a sibling path prefix or a symlinked file, generated code that did not compile when a class name clashed with an import, and left half of a skeleton behind on failure.
- `FakeQueryBus` ignored a configured `null` result; fake buses returned envelopes without the dispatched stamps.
- `MessageTypeLocator` walked the class hierarchy again for every message without a match and was reset after every worker message.
- `ContainerHelper` registered abstract classes as services; an exception message contained a line break.
- CI: the lowest-dependency job (DBAL 4.0, Messenger 7.2) and the coverage job failed.

### Removed
- `HandlerLocatorRegistrar`, `MessageTypeLocatorResetter` and the unused `message_types` attribute of the stamp decider tag (all internal).
- The `somework_cqrs.discovered_messages` container parameter (internal).

## [0.4.0] - 2026-03-23

### Added
- `CommandBusInterface`, `QueryBusInterface` and `EventBusInterface`, implemented by the real and the fake buses and autowired.
- Attribute-only handlers (`#[AsCommandHandler]` etc. without implementing a handler interface).
- `#[Asynchronous]` attribute and `AsynchronousStampDecider`.
- OpenTelemetry bridge (`OpenTelemetryMiddleware`, enabled when `open-telemetry/api` is installed).
- Callback assertions in `CqrsAssertionsTrait`; rate-limit logging.
- Symfony Flex recipe files, MkDocs documentation site, getting-started, migration and middleware guides, example application.
- CI coverage, `composer validate`, audit and lowest-dependency jobs; Dependabot; `SECURITY.md`, `CONTRIBUTING.md`, issue and pull request templates.

### Changed
- `StampDecider` is part of the public API.
- The handler interfaces no longer type the `__invoke()` parameter.
- Generated message and handler skeletons contain placeholder properties and a constructor.

## [0.3.0] - 2026-03-23

This release was documented as "1.0.0" to "3.0.0" in earlier revisions of this file; those versions were never tagged.

### Added
- Typed bus facades `CommandBus`, `QueryBus` and `EventBus` with sync/async dispatch via `DispatchMode`.
- Stamp pipeline with deciders for retry policies, transports, serializers, metadata and dispatch-after-current-bus; per-message configuration resolved through the class hierarchy and interfaces.
- `#[AsCommandHandler]`, `#[AsQueryHandler]` and `#[AsEventHandler]`; `EnvelopeAware` handlers and `Abstract*Handler` base classes.
- Console commands `somework:cqrs:list`, `somework:cqrs:generate`, `somework:cqrs:debug-transports` and `somework:cqrs:health`.
- `HandlerRegistry`, compile-time handler count validation, transport name validation.
- PSR-3 logging; `NoHandlerException`, `MultipleHandlersException`, `AsyncBusNotConfiguredException`, `RateLimitExceededException`.
- Testing helpers: `FakeCommandBus`, `FakeQueryBus`, `FakeEventBus`, `CqrsTestCase`, `CqrsAssertionsTrait`, `DispatchedMessage` constraint.
- `CausationIdMiddleware`, `IdempotencyStamp` with the `DeduplicateStamp` bridge, `RetryConfiguration` with `CqrsRetryStrategy`, `ExponentialBackoffRetryPolicy`.
- Event ordering (`SequenceAware`, `AggregateSequenceStamp`), rate limiting, transactional outbox (`DbalOutboxStorage`, relay command, schema subscriber).
- `@api` / `@internal` annotations and the backward compatibility promise in `UPGRADE.md`.

### Changed
- `ExponentialBackoffRetryPolicy::getStamps()` returns no stamps; delays are applied by `CqrsRetryStrategy` at the transport level.

### Removed
- Symfony 6.4 support; Symfony 7.2 or newer is required.

## [0.2.4] - 2025-10-11
### Changed
- Synchronous command dispatch returns the handler result.

## [0.2.3] - 2025-10-11
### Fixed
- Registration of the event no-handler middleware on traced (debug) buses.

## [0.2.2] - 2025-10-11
### Changed
- Documented the event bus no-handler middleware.

## [0.2.1] - 2025-10-11
### Fixed
- Handler resolution for queries.

## [0.2.0] - 2025-10-11
### Changed
- Generated services are private; optional stamp classes are checked once; broader stamp decider coverage.

## [0.1.2] - 2025-10-06
### Fixed
- Union types when resolving the message class of a handler.

## [0.1.1] - 2025-10-06
### Fixed
- Handler locator decoration for aliased Messenger buses.

## [0.1.0] - 2025-10-06
### Added
- Command, query and event buses on top of Symfony Messenger with automatic handler discovery.
- Metadata stamps and providers for correlation details.
- Async bus configuration, handler listing and message/handler generator commands.

[Unreleased]: https://github.com/somework/cqrs/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/somework/cqrs/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/somework/cqrs/compare/v0.2.4...v0.3.0
[0.2.4]: https://github.com/somework/cqrs/compare/v0.2.3...v0.2.4
[0.2.3]: https://github.com/somework/cqrs/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/somework/cqrs/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/somework/cqrs/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/somework/cqrs/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/somework/cqrs/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/somework/cqrs/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/somework/cqrs/releases/tag/v0.1.0
