# CQRS Bundle Example App

A minimal Symfony application demonstrating [somework/cqrs-bundle](https://github.com/somework/cqrs) with a task-management domain.

> This is an in-repo example for exploration. For a real project, start with
> `composer create-project symfony/skeleton` and then `composer require somework/cqrs-bundle`.

## What is this?

A working Symfony app that uses all three bus types provided by the CQRS bundle:

- **Commands** -- create and complete tasks (`CreateTask`, `CompleteTask`)
- **Queries** -- retrieve tasks (`FindTaskById`, `ListTasks`)
- **Events** -- react to side effects (`TaskCreated`)

Handlers are discovered automatically via PHP attributes and marker interfaces.

## Quick Start

```bash
# Clone the repository (if you haven't already)
git clone https://github.com/somework/cqrs.git
cd cqrs/docs/example-app

# Install dependencies
composer install

# Run with Docker
docker compose up

# Or run directly with PHP
php -S 127.0.0.1:8000 -t public/

# List registered CQRS handlers
php bin/console somework:cqrs:list

# Generate a new message + handler pair
php bin/console somework:cqrs:generate --type=command
```

## What to look at

| File | What it shows |
|------|---------------|
| `src/Task/Command/CreateTask.php` | Immutable command DTO with `readonly` properties |
| `src/Task/Command/CreateTaskHandler.php` | Handler using `#[AsCommandHandler]` attribute + `CommandHandler` interface |
| `src/Task/Query/ListTasks.php` | Zero-property query (valid pattern for "list all" queries) |
| `src/Task/Event/TaskCreated.php` | Event dispatched as a side effect of command handling |
| `src/Task/Event/TaskCreatedHandler.php` | Event handler demonstrating fire-and-forget pattern |
| `src/Task/InMemoryTaskStore.php` | Simple storage service injected into handlers |
| `config/packages/somework_cqrs.yaml` | Bundle configuration with commented examples |
| `config/packages/messenger.yaml` | Messenger transport setup (async commented out for simplicity) |

## Key Concepts Demonstrated

**Auto-discovery** -- Handlers are found automatically. No manual service wiring needed. The bundle scans for `#[AsCommandHandler]`, `#[AsQueryHandler]`, and `#[AsEventHandler]` attributes.

**Typed buses** -- Commands, queries, and events each have their own bus with distinct semantics. Commands and events support sync/async dispatch. Queries are always synchronous.

**Stamp pipeline** -- Per-message configuration for retry policies, transport routing, serializers, and metadata providers. See the commented examples in `somework_cqrs.yaml`.

**Handler contracts** -- Each handler implements both a PHP attribute (for discovery) and a marker interface (for type safety). The `__invoke()` method receives the typed message directly.

## Learn more

- [Getting Started Guide](../../docs/getting-started.md)
- [Usage Documentation](../../docs/usage.md)
- [Configuration Reference](../../docs/reference.md)
- [Bundle README](../../README.md)
