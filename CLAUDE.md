# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A Symfony bundle (`somework/cqrs-bundle`) that wires Command, Query, and Event buses on top of Symfony Messenger. It auto-discovers handlers via PHP attributes and marker interfaces, and provides a configurable stamp pipeline for retry policies, serialization, metadata, and transport routing.

## Commands

```bash
# Install dependencies
composer install

# Run tests (all)
vendor/bin/phpunit

# Run a single test file
vendor/bin/phpunit tests/Bus/CommandBusTest.php

# Run a single test method
vendor/bin/phpunit --filter testMethodName

# Static analysis (level 8)
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist

# Code style (fix)
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --allow-risky=yes

# Code style (dry-run check)
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --allow-risky=yes --dry-run --diff

# Outbox tests on a real database instead of in-memory SQLite (tables are dropped and recreated)
CQRS_TEST_DATABASE_URL='pdo-pgsql://user:secret@127.0.0.1:5432/cqrs_test?serverVersion=16' vendor/bin/phpunit --group database
```

CI runs all three checks (php-cs-fixer, phpstan, phpunit) across PHP 8.2, 8.3, 8.4 and 8.5 with the highest dependencies (Symfony 8 on PHP 8.4+, Symfony 7.4 below), plus a lowest-dependency job (PHP 8.2, Symfony 7.2, DBAL 4.0), a minimal install without optional packages, the `database` test group on PostgreSQL 16 and MySQL 8.4, an example-app smoke test and `mkdocs build --strict`.

Supported: PHP 8.2+, Symfony `^7.2 || ^8.0`. Versions follow the 0.x line (latest tag v0.4.0, next release 0.5.0); record every user-visible change in `CHANGELOG.md` ([Unreleased]) and every behaviour change in `UPGRADE.md`.

### Console Commands

- `somework:cqrs:list` — shows registered commands, queries, and events with handler metadata (`--type=`, `--details`)
- `somework:cqrs:generate <type> <FQCN>` — scaffolds a message + attribute-based handler following the project's PSR-4 mapping (`--handler=`, `--dir=`, `--force`)
- `somework:cqrs:debug-transports` — shows the bundle's `transports` configuration (not `framework.messenger.routing` or `#[Asynchronous]`)
- `somework:cqrs:health` — instantiates every handler and Messenger transport; exit code 0/1/2
- `somework:cqrs:outbox:relay|setup|failed|purge` — transactional outbox operations (registered when `outbox.enabled`); the relay claims fetched rows with a run token before sending them (an interrupted claim is retried alone; unattempted claims are released; publish marks are flushed every 2 s), retries failing rows with backoff, gives up after `outbox.max_attempts` (3× for transport failures) and pauses a transport after 3 consecutive send failures

## Architecture

### Message Flow

```
Bus::dispatch(message, mode, ...stamps)
  → DispatchModeDecider resolves sync/async/outbox (exact map entry → #[Outbox]/#[Asynchronous] → parent/interface map entry → default)
  → outbox mode: the transaction is checked first, the stamp pipeline runs as for async, and the envelope goes
    through the async bus (sync bus without one) with a StoreInOutboxStamp; OutboxPrepareMiddleware (after
    add_default_stamps) hides DeduplicateStamps and drops deferral, and OutboxStoreMiddleware (before
    doctrine_transaction/send_message) stores it in the current transaction (OutboxStoredStamp) instead of sending
    it; the relay's dispatch carries RelayedFromOutboxStamp, so re-added stamps give way to the stored ones
  → StampsDecider runs the stamp pipeline (rate limit, retry, transport, serializer, metadata, sequence,
    causation id, idempotency, dispatch-after-current-bus); caller stamps always win
  → Symfony Messenger MessageBusInterface::dispatch() on the sync or async bus
  → bundle middleware right after dispatch_after_current_bus (OpenTelemetry, causation id,
    allow-no-handler for events), the deduplication lock release right after Messenger's
    deduplicate middleware, then Messenger's handlers/senders
```
`dispatchSync()` and `ask()` read the result through `SynchronousResult` (unwraps a single handler exception,
reports messages that were sent to a transport or deduplicated).

### Key Layers

**Contracts** (`src/Contract/`) — Marker interfaces for message types (`Command`, `Query`, `Event`) and their handlers (`CommandHandler`, `QueryHandler`, `EventHandler`; no methods, handlers type-hint the concrete message in `__invoke()`). Bus interfaces (`CommandBusInterface`, `QueryBusInterface`, `EventBusInterface`). Policy contracts: `MessageNamingStrategy`, `RetryPolicy`, `RetryConfiguration`, `MessageSerializer`, `MessageMetadataProvider`, `Contract\Outbox\OutboxStorage` (claim-token contract: `claim`/`renew`/`release`/`markPublished`/`recordFailure`; plus the optional capabilities `Contract\Outbox\{OutboxSchema, FailedOutboxMessages, OutboxMonitoring, TransactionalOutbox}`), `StampDecider`. Handlers may implement `EnvelopeAware` to receive the Messenger envelope.

**Buses** (`src/Bus/`) — `CommandBus` and `EventBus` extend `AbstractMessengerBus` and support sync/async/outbox dispatch via the `DispatchMode` enum. `QueryBus` is standalone, sync-only and validates exactly one handler result.

**Attributes** (`src/Attribute/`) — `#[AsCommandHandler]`, `#[AsQueryHandler]`, `#[AsEventHandler]` — repeatable PHP attributes that accept message FQCN and optional bus name. Registered for autoconfiguration in `CqrsExtension`.

**Console Commands** (`src/Command/`) — diagnostics, scaffolding, health and outbox commands. See Console Commands section above.

**DI / Compiler Passes** (`src/DependencyInjection/`) — `CqrsExtension` loads `config/services.php` (fixed services only) and runs the registrars in `Registration/` that wire resolvers, stamp deciders, outbox and rate limiting from bundle config. Compiler passes (see `.claude/rules/di-registrar-pattern.md` for phases):
- `ValidateBusIdsPass` — every `buses.*` id must be a Messenger bus
- `CqrsHandlerPass` — normalises handler tags: infers messages from `__invoke()` types, assigns sync + async buses, resolves bus aliases, records `somework_cqrs.handler_metadata`
- `EnvelopeAwareHandlersLocatorPass` — decorates each bus handlers locator for `EnvelopeAware` handlers
- `AllowNoHandlerMiddlewarePass`, `CausationIdMiddlewarePass`, `OpenTelemetryMiddlewarePass`, `DeduplicationLockReleasePass` — insert middleware via `MessengerMiddlewareInjector`
- `OutboxStoreMiddlewarePass` — with the outbox enabled, inserts `OutboxPrepareMiddleware` after `add_default_stamps_middleware` and `OutboxStoreMiddleware` before `doctrine_transaction`/`send_message` on the CQRS buses, and gives `OutboxWriter` the Messenger transport names
- `HealthCheckerLocatorPass` — service locators of handlers and transports for the health checkers
- `CqrsRetryStrategyPass` — per-transport `CqrsRetryStrategy`
- `OutboxRelayLockPass` — scopes the relay lock with `framework.cache.prefix_seed`
- `OutboxSigningSecretPass` — signs outbox rows with `kernel.secret` unless `outbox.signing.secret` is set (fails clearly without a secret)
- `OutboxStoragePass` — keeps setup, failed, health and the relay's schema report on the configured storage (`somework_cqrs.outbox.base_storage`) when the application decorates `somework_cqrs.outbox.storage`, and checks that a custom storage implements `OutboxStorage`
- `TransportRoutingPass` — tells `MessageTransportStampDecider` which messages `framework.messenger.routing` routes (a bare `#[Asynchronous]` defers to that routing)
- `LoggerChannelPass` — moves the bundle's services to the `cqrs` Monolog channel (declared in `CqrsExtension::prepend()`)
- `ValidateConfiguredServicesPass` — every service id and rate limiter named in the configuration exists and implements the interface its option needs (the error names the config path)
- `ValidateHandlerCountPass`, `ValidateTransportNamesPass`, `ValidateIdempotencyDependenciesPass` — validation
- `RemoveHandlerMetadataParameterPass` — drops the handler metadata parameter after `HandlerRegistry` received it

**Stamp Pipeline** (`src/Support/`) — `StampsDecider` aggregates `StampDecider` implementations sorted by priority (see `.claude/rules/stamp-decider-pipeline.md`). Resolver-backed deciders walk class hierarchy + interfaces to find message-specific config (exact match → parent classes → interfaces → type default → global default).

**Registry** (`src/Registry/`) — `HandlerRegistry` provides read-only access to compiled handler metadata (`HandlerDescriptor` DTOs). Used by the `somework:cqrs:list` console command.

**Messenger Integration** (`src/Messenger/`) — `EnvelopeAwareHandlersLocator` decorates Messenger's locator to inject envelopes into `EnvelopeAware` handlers. Middleware: `AllowNoHandlerMiddleware` (events), `CausationIdMiddleware`, `OpenTelemetryMiddleware`, `DeduplicationLockReleaseMiddleware`, `OutboxPrepareMiddleware`, `OutboxStoreMiddleware`.

**Outbox / Health / Retry / Testing** — `src/Outbox/` (`OutboxWriter`, `DbalOutboxStorage` with its table in `Dbal\DbalOutboxSchema`, `OutboxMessage::fromEnvelope()`, and `Relay\OutboxRelay`, the relay loop the console command runs through a `RelayReporter`; `Signing\OutboxSigner` + `SigningOutboxStorage` sign stored rows and the relay verifies them before decoding), `src/Health/` (`HealthChecker` extension point), `src/Retry/CqrsRetryStrategy`, `src/Testing/` (fake buses and assertions for applications).

### Configuration

All options live under `somework_cqrs` key. The tree-builder is in `Configuration.php`. Every per-message section has one shape: an optional global `default` (retry policies, serialization, metadata, naming, rate limiting) and per type (`command`, `query`, `event`) a `default` + `map` for message-specific overrides of retry policies, serializers, metadata providers, transport names, dispatch modes, dispatch-after-current-bus and rate limiters. Options moved since 0.4 fail with a "moved to …" message. Map keys must be existing classes/interfaces and service ids non-empty strings (validated in the tree); `enabled` flags that decide which services exist reject env placeholders.

### Test Structure

Tests mirror `src/` structure. `tests/Fixture/` contains stub messages, handlers, and kernel setups for functional tests. `tests/Functional/` tests the full container compilation and dispatch flow, including a real async round trip (`AsyncTransportRoundTripTest`) and the minimal install without bundle configuration (`MinimalInstallTest`).

### Detailed Rules

`.claude/rules/` contains 7 architecture-specific rule files covering: DI registrar/compiler pass patterns, resolver hierarchy walk, stamp decider pipeline, message design, handler contract, bus dispatch semantics, and test conventions. Each rule is path-scoped to its relevant source directory.
