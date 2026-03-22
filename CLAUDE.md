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

# Static analysis (level 6)
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist

# Code style (fix)
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --allow-risky=yes

# Code style (dry-run check)
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --allow-risky=yes --dry-run --diff
```

CI runs all three checks (php-cs-fixer, phpstan, phpunit) across PHP 8.2, 8.3, and 8.4.

### Console Commands

- `somework:cqrs:list` — shows registered commands, queries, and events with handler metadata
- `somework:cqrs:generate` — scaffolds a message + handler skeleton (`--type=command|query|event`, `--dir=`, `--force`)
- `somework:cqrs:debug-transports` — inspects Messenger transport routing for CQRS messages

## Architecture

### Message Flow

```
Bus::dispatch(message, mode)
  → DispatchModeDecider resolves sync/async
  → StampsDecider runs stamp pipeline (retry, transport, serializer, metadata, dispatch-after-current-bus)
  → Symfony Messenger MessageBusInterface::dispatch()
```

### Key Layers

**Contracts** (`src/Contract/`) — Marker interfaces for message types (`Command`, `Query`, `Event`) and their handlers (`CommandHandler`, `QueryHandler`, `EventHandler`). Policy contracts: `MessageNamingStrategy`, `RetryPolicy`, `MessageSerializer`, `MessageMetadataProvider`. Handlers may implement `EnvelopeAware` to receive the Messenger envelope.

**Buses** (`src/Bus/`) — `CommandBus`, `QueryBus`, `EventBus` extend `AbstractMessengerBus`. CommandBus and EventBus support sync/async dispatch via `DispatchMode` enum. QueryBus is sync-only and validates exactly one handler result.

**Attributes** (`src/Attribute/`) — `#[AsCommandHandler]`, `#[AsQueryHandler]`, `#[AsEventHandler]` — repeatable PHP attributes that accept message FQCN and optional bus name. Registered for autoconfiguration in `CqrsExtension`.

**Console Commands** (`src/Command/`) — Three Symfony console commands for diagnostics and scaffolding. See Console Commands section above.

**DI / Compiler Passes** (`src/DependencyInjection/`) — `CqrsExtension` loads services and runs 11 registrars that wire bus facades, stamp deciders, and resolvers from bundle config. Three compiler passes:
- `CqrsHandlerPass` — discovers handlers, infers message types from `__invoke()` parameter type, builds handler metadata
- `AllowNoHandlerMiddlewarePass` — adds middleware to suppress `NoHandlerForMessageException` for events
- `ValidateTransportNamesPass` — validates configured transport references

**Stamp Pipeline** (`src/Support/`) — `StampsDecider` aggregates `StampDecider` implementations. Five built-in deciders add stamps for retry, transport, serializer, metadata, and dispatch-after-current-bus. Each is backed by a resolver that walks class hierarchy + interfaces to find message-specific config (exact match → parent classes → interfaces → type default → global default).

**Registry** (`src/Registry/`) — `HandlerRegistry` provides read-only access to compiled handler metadata (`HandlerDescriptor` DTOs). Used by the `somework:cqrs:list` console command.

**Messenger Integration** (`src/Messenger/`) — `EnvelopeAwareHandlersLocator` decorates Messenger's locator to inject envelopes into `EnvelopeAware` handlers. `AllowNoHandlerMiddleware` silences missing-handler errors for events.

### Configuration

All options live under `somework_cqrs` key. The tree-builder is in `Configuration.php`. Per-type sections (`command`, `query`, `event`) support `default` + `map` for message-specific overrides of retry policies, serializers, metadata providers, transport names, and dispatch modes.

### Test Structure

Tests mirror `src/` structure. `tests/Fixture/` contains stub messages, handlers, and kernel setups for functional tests. `tests/Functional/` tests the full container compilation and dispatch flow.

### Detailed Rules

`.claude/rules/` contains 7 architecture-specific rule files covering: DI registrar/compiler pass patterns, resolver hierarchy walk, stamp decider pipeline, message design, handler contract, bus dispatch semantics, and test conventions. Each rule is path-scoped to its relevant source directory.
