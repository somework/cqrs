# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the major version is 0, minor releases may contain breaking changes; they are listed under "Changed" and explained in [UPGRADE.md](UPGRADE.md).

## [Unreleased]

Planned as 0.5.0. Entries marked **Breaking** need changes in applications; [UPGRADE.md](UPGRADE.md#upgrading-from-040-to-050) explains each of them.

### Added

**Buses and handlers**
- Symfony 8 support (`^7.2 || ^8.0`).
- `MessageSentToTransportException` and `DuplicateMessageException` for `CommandBus::dispatchSync()` and `QueryBus::ask()`.
- `CqrsException`, implemented by every exception of the bundle; the bus interfaces document what they throw.
- `@implements Query<ResultType>` types the result of `QueryBusInterface::ask()` for static analysis; the handler and query interfaces have template defaults.
- `#[AsEventHandler(priority: …, fromTransport: …)]`; a `fromTransport` that names no Messenger transport fails the build.
- `TraceContextStamp`: the W3C trace context travels from the dispatching process to the worker.
- `MessageMetadataStamp::getMessageId()`: every message has its own id.
- `DeduplicationLockReleaseMiddleware`: a failed synchronous dispatch releases its idempotency lock.

**Configuration**
- A global `default` for `retry_policies` and `rate_limiting`, and a per-type `default` for `rate_limiting`.
- Service ids must be non-empty strings; per-message map keys must be existing classes or interfaces (a leading `\` is allowed).
- A service id or rate limiter that does not exist, or a service that does not implement the interface its option needs, fails the build with the configuration path that names it.

**Transactional outbox**
- `OutboxWriter` (`@api`) stores a message in one call, once per transport an asynchronous dispatch would use; `OutboxMessage::fromEnvelope()` builds rows with time-ordered UUIDv7 ids.
- The commands `somework:cqrs:outbox:setup`, `…:failed` (list, `--requeue`, `--transport`, `--sign`) and `…:purge`; the options `outbox.storage`, `connection`, `serializer`, `auto_setup`, `max_attempts` and `signing`.
- Retries with an exponential backoff (1 minute up to 1 hour); a row is given up after `outbox.max_attempts` attempts, or three times as many when its transport fails.
- The relay claims each fetched batch with a token of its run before sending, and renews the claims of its batch every 20 seconds, so a slow send does not let another relay take them over. A row whose attempt was interrupted (the process died) is retried on its own, keeps the error of the attempt before, and is given up after three times `max_attempts`. Unattempted claims are released, also when a send throws, and sent rows are marked as published at most 2 seconds later, also while a slow send is running.
- A transport that fails 3 times in a row (10 times, or 3 over 10 seconds, once it accepted a message in the run) is paused until the next run; the others go on. The transports take turns, and new rows go before retries.
- A row stored for a transport that does not exist is given up at once, with an error that says how to fix it.
- Signed rows (HMAC-SHA256, `outbox.signing`, on by default with `framework.secret`): the relay only decodes rows with a valid signature. `previous_secrets` supports a rotation, and `accept_unsigned` lets rows of 0.4 drain.
- Capability interfaces `Contract\Outbox\OutboxSchema`, `FailedOutboxMessages` and `OutboxMonitoring`, with the `FailedOutboxMessage` and `OutboxStatus` DTOs. With them, setup, failed and health work with any storage, also behind a decorator. The interfaces are autowired to the configured storage when it implements them.
- `outbox:failed --requeue --sign <ids>` shows the class in each body next to its type header and a digest of the body, and refuses to sign a row whose type header names another class.
- A single relay at a time when symfony/lock is installed. The lock is scoped to `framework.cache.prefix_seed` (or the project directory), the connection and the table, and extended every 10 seconds.
- SIGTERM and SIGINT stop the relay after the current row with exit code 1; after a PHP fatal error it still releases its lock.
- An outbox check in `somework:cqrs:health`: given-up rows, failing rows, due rows waiting more than 10 minutes, claims older than 10 minutes that no relay took over after their retry time, and a table that needs the setup command. Counts stop at 10 000 rows.
- The indexes `idx_<table>_pending` for the relay and `idx_<table>_claimed` for the health check. `setup` builds it with `CREATE INDEX CONCURRENTLY` on PostgreSQL, and serialises concurrent setups with a database lock. It gives up after 5 seconds instead of blocking writes, notices a transaction pooler, and exits with `128 + signal`.
- The automatic setup creates the table or adds the columns without waiting in the table's lock queue. It never builds indexes, and never runs inside a transaction.
- `DbalOutboxStorage::pendingChanges()` lists what `setup` still has to do.
- `database.table` names work on MySQL and MariaDB. An unqualified name is found along the PostgreSQL search path.

**Diagnostics and tooling**
- The bundle logs on its own `cqrs` channel when MonologBundle is installed: one debug line per dispatch, plus one per stamp decider that changed the stamps.
- A warning log when an asynchronous dispatch was handled synchronously because no transport is configured.
- The compilation log explains why idempotency cannot deduplicate, and the first `IdempotencyStamp` of a process logs it as a warning.
- `somework:cqrs:list` prints a compact table per message type, filters with `--message`, and marks retry policies that no transport uses.
- `somework:cqrs:generate` writes the imports of a handler in alphabetical order.

**Testing and API**
- `Testing\RecordedDispatch`, `FakeCommandBus::willReturnFor()`, and `willThrow()` on the command and query fakes. A failed `assertDispatched()` names the fake bus.
- `FakeQueryBus::willReturnFor()` is typed by the query's `@implements Query<…>`, and both `willReturnFor()` refuse an interface or abstract class (results are matched by the concrete class).
- `Registry\MessageType` for `HandlerRegistry::byType()`.
- The backward compatibility promise (UPGRADE.md) covers the configuration tree, documented service ids and tags, decider priorities, console commands and span names, and has a deprecation policy.
- Part of the public API (`@api`): the health checker types, the outbox contracts and DTOs, `DbalOutboxStorage`, `HandlerRegistry` and `HandlerDescriptor`, the default policies and `SomeWorkCqrsBundle`.

### Changed

**Breaking**
- The abstract handlers are removed: implement the marker interface with a typed `__invoke()`, plus `EnvelopeAware` and `EnvelopeAwareTrait` for the envelope.
- `StampDecider` and `MessageTypeAwareStampDecider` moved to `SomeWork\CqrsBundle\Contract`, and the default policies to `SomeWork\CqrsBundle\Policy`.
- `HandlerRegistry::byType()` takes a `MessageType`, and `HandlerDescriptor::$type` is one. Exceptions expose `$messageClass`. The fake buses record `RecordedDispatch` objects.
- One configuration shape for every per-message section. `async.dispatch_after_current_bus` moved to `dispatch_after_current_bus`, and `naming.<type>` to `naming.<type>.default`. `transports.*.stamp` is removed. Old options fail with a message naming the new place.
- A message dispatched by a handler inherits the correlation id of the handled message, and its causation id is the message id of the handled message (it was its correlation id).
- The container no longer autowires the internal services (`DispatchModeDecider`, `DispatchAfterCurrentBusDecider`, `TransportMappingProvider`, `CausationIdContext`) by class name.
- `OutboxStorage` v2: `fetchUnpublished($limit, $excludedTransports)`, `claim()`, `release()`, `markPublished(array $ids)`, `recordFailure()` and `purgePublished()`. `OutboxMessage` gains `attempts`, `lastError`, `claimedAt`, `availableAt` and `signature`, and ids are lowercased.
- The outbox table gains seven columns and two indexes; writes need the columns: run `somework:cqrs:outbox:setup` before deploying.
- `OutboxStorage` moved to `SomeWork\CqrsBundle\Contract\Outbox`, and gained `renew()`.
- `DbalOutboxStorage` is no longer autowired by its class name: type-hint the capability interfaces, or `somework_cqrs.outbox.base_storage`.
- The relay gives up unsigned rows unless `outbox.signing.accept_unsigned` is set.
- `DbalOutboxStorage::status()` returns an `OutboxStatus`, and `fetchFailed()` returns `FailedOutboxMessage` objects.
- `dispatchSync()` and `ask()` rethrow the exception of the single failing handler instead of `HandlerFailedException`, and raise `NoHandlerException` instead of `NoHandlerForMessageException`.
- `dispatchSync()` throws `MultipleHandlersException` when more than one handler ran.
- The `enabled` flags (`outbox`, `outbox.signing`, `idempotency`, `causation_id`, `sequence`, `rate_limiting`) and every option the compilation needs reject environment variables with a clear message. Environment variables remain allowed in `retry_strategy.jitter`/`max_delay`, `idempotency.ttl`, `outbox.auto_setup`/`max_attempts`, `outbox.signing.secret`/`previous_secrets`/`accept_unsigned` and the `dispatch_after_current_bus` flags.
- New compile errors:
  - a handler attribute whose message the handler method does not accept, or whose type contradicts the message;
  - a query handler declared `: void`;
  - `#[Asynchronous]` without an async bus or transport;
  - a non-bus id under `buses.*` or `causation_id.buses`;
  - an unknown transport under `retry_strategy.transports`.
- `psr/container`, `symfony/filesystem` and `symfony/service-contracts` are direct dependencies. Older `doctrine/dbal`, `open-telemetry/api`, `symfony/lock` and `symfony/rate-limiter` versions are declared as conflicts.
- The bundle registers only its own services. The testing fakes are no longer services.

**Dispatch**
- Handlers without an explicit `bus` are registered on the sync bus of their type and on its async bus.
- `CommandHandler`, `QueryHandler` and `EventHandler` are pure marker interfaces, so handlers can type-hint the concrete message.
- `#[Asynchronous]` sends messages dispatched with `DispatchMode::DEFAULT` to the async bus. Its transports follow the configuration precedence, and a bare attribute no longer overrides Messenger's routing.
- Stamps passed by the caller take precedence over the stamp pipeline, retry policy stamps included.
- `IdempotencyStamp` stays on the envelope next to the `DeduplicateStamp` it produces.
- `dispatchSync()` and `ask()` ignore `DispatchAfterCurrentBusStamp`.
- Per-message maps resolve interfaces most specific first.
- Resolvers resolve each message class once, so policies must be stateless.
- `DispatchAfterCurrentBusStampDecider` runs at priority -10.
- The bundle middleware runs right after Messenger's `dispatch_after_current_bus` middleware. It is only added to the default bus when a facade falls back to it.
- OpenTelemetry records one span per pass: `cqrs.dispatch <Message>` (PRODUCER) and `cqrs.consume <Message>` (CONSUMER).
- `CqrsRetryStrategy` falls back to Messenger's `MultiplierRetryStrategy` defaults instead of retrying forever, and caps delays before and after jitter.
- `retry_strategy.transports` keys are kept as written.
- Rate limiting stays inactive until a limiter is configured. `RateLimitResolver` accepts any `RateLimiterFactoryInterface`.
- `ValidateHandlerCountPass` checks commands and queries per bus and counts distinct services.
- A handler of several handler interfaces registers each union member under its own type.

**Outbox and commands**
- The relay dispatches each message on the bus of its type, honours the stored transport name, and exits with 1 when a row failed and with 2 for an invalid `--limit`; `--limit` counts processed rows.
- The relay reads the transport list again after a short fetch or after 10 seconds, and reads up to 50 transports in one `UNION ALL` statement (rows are grouped by branch, so a case-insensitive collation of `transport_name` cannot mix them up).
- Outbox dates are stored in UTC. The table is never created inside an open transaction.
- The outbox commands exit with 1 and a message when the database fails. `--older-than` accepts only `<number> <unit>`. `outbox.table_name` must be an identifier that is not a reserved word.
- `somework:cqrs:health` instantiates every CQRS handler and every Messenger transport.
- `somework:cqrs:generate` follows the PSR-4 mapping of the project and validates its input.
- `somework:cqrs:list` exits with 2 for an unknown `--type`.

### Removed
- `AbstractCommandHandler`, `AbstractQueryHandler`, `AbstractEventHandler` and `MessageTransportStampFactory`.
- The internal `HandlerLocatorRegistrar`, `MessageTypeLocatorResetter`, `AsynchronousStampDecider`, the `somework_cqrs.discovered_messages` and `somework_cqrs.transport_stamp_types` parameters, and the `message_types` attribute of the stamp decider tag.

### Fixed
- The default installation (no bundle configuration, no symfony/rate-limiter) failed to compile.
- Bus aliases such as `messenger.default_bus` broke handler registration and envelope injection.
- Workers failed with "No handler for message" because handlers were missing on the async bus.
- Per-message retry policies, `dispatch_after_current_bus` overrides and rate-limiter maps failed, or were ignored, because their service locators were wrapped twice.
- The second envelope-aware handler of a message on a bus was skipped, and `HandledStamp` handler names were wrong.
- A nested dispatch handled by the same envelope-aware handler service left the outer invocation with the inner envelope.
- Stamp deciders were registered twice, and `idempotency.enabled: false` had no effect.
- Handlers with a typed `__invoke()` caused a PHP fatal error.
- Union types dropped non-CQRS members, and option-less handler tags became unrestricted registrations.
- Envelope-aware handlers failed on buses that are not CQRS buses.
- An `idempotency.ttl` from an environment variable became 0.
- `dispatchSync()` and `ask()` reported a misleading `NoHandlerException` for sent or deduplicated messages.
- The container could not be compiled with an OpenTelemetry tracer provider, and exceptions were recorded twice on spans.
- The idempotency lock stayed held for the whole TTL after a failed synchronous dispatch. The compilation log warns about lock stores that cannot deduplicate.
- A failed async dispatch without an async bus consumed a rate-limiter token.
- `CausationIdContext::pop()` threw on an empty stack.
- The outbox:
  - committed or aborted the caller's transaction when it created its table;
  - published messages twice under concurrent relays, and stalled on a failing row;
  - ordered messages randomly within a second, and stored dates without a time zone;
  - generated index names longer than 63 characters;
  - marked rows as published when their message class could not be loaded (symfony/messenger 7.4+);
  - dispatched relayed messages on the default bus;
  - added its table to the schema of every connection.
- `somework:cqrs:health` reported every handler and transport as CRITICAL.
- `somework:cqrs:generate` could write outside the PSR-4 layout and the project directory, generated code that did not compile, and left half a skeleton behind on failure.
- `FakeQueryBus` ignored a configured `null` result, and the fake buses returned envelopes without the dispatched stamps.
- `MessageTypeLocator` walked the class hierarchy again for every unmatched message.
- `ContainerHelper` registered abstract classes as services.

### Security
- Outbox rows are signed, and the relay verifies them before decoding, so rows written around the application (e.g. through an SQL injection) never reach `unserialize()` (see docs/outbox.md, "Signed rows").
- The relay drops non-sendable stamps (`ReceivedStamp`, …) and `HandledStamp` from a decoded row.
- A fetch of the relay reads at most 8 MiB of message bodies.
- Errors that the relay stores and prints, and the output of `outbox:failed`, contain no control characters.
- Table names and generated class names with a trailing newline are rejected.
- Documented: least-privilege roles for the outbox, JSON serialization, personal data in stored errors, and scoping idempotency keys.

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
