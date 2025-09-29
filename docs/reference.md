# Configuration reference

The bundle exposes the `somework_cqrs` configuration tree. Every option accepts
a service id or fully-qualified class name. When you pass a class name the
bundle will register it as an autowired, autoconfigured service automatically.

```yaml
# config/packages/somework_cqrs.yaml
somework_cqrs:
    default_bus: messenger.default_bus
    buses:
        command: messenger.bus.commands
        command_async: messenger.bus.commands_async
        query: messenger.bus.queries
        event: messenger.bus.events
        event_async: messenger.bus.events_async
    naming:
        default: SomeWork\CqrsBundle\Support\ClassNameMessageNamingStrategy
        command: app.command_naming_strategy            # optional override
        query: null                                     # falls back to default
        event: null
    retry_policies:
        command: SomeWork\CqrsBundle\Support\NullRetryPolicy
        query: app.query_retry_policy
        event: SomeWork\CqrsBundle\Support\NullRetryPolicy
    serialization:
        command: SomeWork\CqrsBundle\Support\NullMessageSerializer
        query: app.query_serializer
        event: SomeWork\CqrsBundle\Support\NullMessageSerializer
```

* **default_bus** – fallback Messenger bus id. Used whenever a type-specific bus
  is omitted.
* **buses** – service ids for the synchronous and asynchronous Messenger buses
  backing each CQRS facade.
* **naming** – strategies implementing
  `SomeWork\CqrsBundle\Contract\MessageNamingStrategy`. They control the human
  readable message names exposed in CLI tooling and diagnostics.
* **retry_policies** – services implementing
  `SomeWork\CqrsBundle\Contract\RetryPolicy`. The buses merge the returned
  stamps into each dispatch call so you can apply custom retry strategies per
  message type.
* **serialization** – services implementing
  `SomeWork\CqrsBundle\Contract\MessageSerializer`. When the service returns a
  `SerializerStamp` it is appended to the dispatch call.

All options are optional. When you omit a setting the bundle falls back to a
safe default implementation that leaves Messenger behaviour unchanged.
