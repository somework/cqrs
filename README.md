# SomeWork CQRS Bundle

The bundle builds on top of Symfony Messenger. Configure dedicated command, query, and event buses so the provided facades can route messages deterministically across environments.

## Messenger configuration

### Shared defaults (`config/packages/messenger.yaml`)

```yaml
framework:
    messenger:
        default_bus: messenger.bus.commands
        buses:
            messenger.bus.commands: ~
            messenger.bus.commands_async:
                default_middleware:
                    enabled: true
            messenger.bus.queries: ~
            messenger.bus.events: ~
            messenger.bus.events_async:
                default_middleware:
                    enabled: true
```

### CQRS bundle defaults (`config/packages/somework_cqrs.yaml`)

```yaml
somework_cqrs:
    default_bus: messenger.bus.commands
    buses:
        command: messenger.bus.commands
        command_async: messenger.bus.commands_async
        query: messenger.bus.queries
        event: messenger.bus.events
        event_async: messenger.bus.events_async
```

### Production transport setup (`config/packages/prod/messenger.yaml`)

Configure real transports and routing so asynchronous commands and events leave the HTTP process.

```yaml
framework:
    messenger:
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    auto_setup: false
        routing:
            'App\\Application\\Command\\GenerateReportCommand': async
            'App\\Domain\\Event\\TaskCreated': async
```

Run the worker with `bin/console messenger:consume async` to process queued messages.

### Development overrides (`config/packages/dev/messenger.yaml`)

Point the async transport at a developer-friendly backend (for example Doctrine or Redis) and allow Messenger to create it on the fly:

```yaml
framework:
    messenger:
        transports:
            async:
                dsn: '%env(resolve:MESSENGER_TRANSPORT_DSN)%'
                options:
                    auto_setup: true
```

### Test overrides (`config/packages/test/messenger.yaml`)

Use an in-memory transport so functional tests can assert on dispatched messages without spawning workers.

```yaml
framework:
    messenger:
        transports:
            async: 'in-memory://'
        routing:
            'App\\Application\\Command\\GenerateReportCommand': async
            'App\\Domain\\Event\\TaskCreated': async
```

With these settings the command, event, and query bus facades provided by the bundle transparently adapt to each environment.
