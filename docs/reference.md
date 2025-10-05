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
        command:
            default: SomeWork\CqrsBundle\Support\NullRetryPolicy
            map:
                App\Application\Command\ShipOrder: app.command.retry_policy
                App\Domain\Contract\RequiresImmediateRetry: app.command.retry_policy_for_interface
        query:
            default: app.query_retry_policy
            map: {}
        event:
            default: SomeWork\CqrsBundle\Support\NullRetryPolicy
            map:
                App\Domain\Event\OrderShipped: app.event.retry_policy
    serialization:
        default: SomeWork\CqrsBundle\Support\NullMessageSerializer
        command:
            default: null
            map:
                App\Application\Command\ShipOrder: app.command.serializer
        query:
            default: app.query_serializer
            map: {}
        event:
            default: SomeWork\CqrsBundle\Support\NullMessageSerializer
            map:
                App\Domain\Event\OrderShipped: app.event.serializer
    dispatch_modes:
        command:
            default: sync
            map:
                App\Application\Command\ShipOrder: async
        event:
            default: sync
            map:
                App\Domain\Event\OrderShipped: async
    async:
        dispatch_after_current_bus:
            command:
                default: true
                map:
                    App\Application\Command\ShipOrder: false
            event:
                default: true
                map: {}
```

* **default_bus** – fallback Messenger bus id. Used whenever a type-specific bus
  is omitted.
* **buses** – service ids for the synchronous and asynchronous Messenger buses
  backing each CQRS facade.
* **naming** – strategies implementing
  `SomeWork\CqrsBundle\Contract\MessageNamingStrategy`. They control the human
  readable message names exposed in CLI tooling and diagnostics.
* **retry_policies** – services implementing
  `SomeWork\CqrsBundle\Contract\RetryPolicy`. Each section defines a `default`
  service applied to the entire message type and an optional `map` of
  message-specific overrides. Keys inside `map` may reference a concrete
  message class, a parent class, or an interface implemented by the message.
  The buses merge the returned stamps into each dispatch call so you can tailor
  retry behaviour per message or shared contracts.
* **serialization** – services implementing
  `SomeWork\CqrsBundle\Contract\MessageSerializer`. Each section mirrors the
  retry policy structure with a global `default`, per-type `default`, and a
  message-specific `map`. The buses resolve serializers in that order and append
  the returned `SerializerStamp` to the dispatch call when provided.
* **dispatch_modes** – controls whether commands and events are dispatched
  synchronously or asynchronously when callers omit the `DispatchMode` argument.
  Each section defines a `default` mode (`sync` or `async`) plus a `map` of
  message-specific overrides. Keys inside `map` may reference a concrete message
  class, a parent class, or an interface implemented by the message. The decider
  walks the class hierarchy and implemented interfaces from most to least
  specific, so explicit class overrides beat interface ones. When a message
  resolves to `async` the bundle routes it through the configured asynchronous
  Messenger bus automatically. If a caller explicitly passes a `DispatchMode`,
  that choice always wins.
  The `CommandBus` and `EventBus` also expose `dispatchSync()` and
  `dispatchAsync()` helpers that forward to `dispatch()` with the corresponding
  mode for convenience.
* **async.dispatch_after_current_bus** – toggles whether the bundle appends
  Messenger's `DispatchAfterCurrentBusStamp` when a command or event resolves to
  the asynchronous bus. Leave the `default` values set to `true` to preserve the
  existing behaviour and enqueue follow-up messages after the current message
  finishes processing. Use the `map` to disable the stamp for specific messages
  that should be sent immediately, even while the current bus is still handling
  handlers.
  Additional stamp logic can be plugged in by implementing
  `SomeWork\CqrsBundle\Support\StampDecider`, tagging it as
  `somework_cqrs.dispatch_stamp_decider`, and letting the bundle run it when
  commands or events are dispatched.

All options are optional. When you omit a setting the bundle falls back to a
safe default implementation that leaves Messenger behaviour unchanged.

When you enable asynchronous defaults you must ensure Messenger workers listen
for the resulting messages. Configure routing in `messenger.yaml` so that any
message marked `async` – either via the `dispatch_modes` defaults or a
per-message override – is delivered to the transport consumed by your workers.
This keeps the CQRS facades consistent with the Messenger routing you already
use for explicit async dispatch calls.

## Handler registry service

The bundle stores compiled handler metadata in the
`SomeWork\CqrsBundle\Registry\HandlerRegistry` service. You can rely on it to
power diagnostics, smoke tests, or documentation pages. The registry exposes:

* `all()` – returns every handler as a list of `HandlerDescriptor` value
  objects.
* `byType('command'|'query'|'event')` – limits the descriptors to one message
  type.
* `getDisplayName(HandlerDescriptor)` – resolves a human-friendly name using the
  configured naming strategies.

## Console reference

Two console commands ship with the bundle:

* `somework:cqrs:list` – Prints the handler catalogue in a table. Accepts the
  `--type=<command|query|event>` option multiple times. The command is safe to
  run in production and reflects the container compiled for the current
  environment.
* `somework:cqrs:generate <type> <class>` – Scaffolds a message and handler pair
  for the chosen type. Optional flags:
  * `--handler=` to customise the handler class name.
  * `--dir=` to override the base directory (defaults to `<project>/src`).
  * `--force` to overwrite existing files instead of aborting.

Both commands are registered automatically when the bundle is enabled.
