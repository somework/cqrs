# Event ordering

The bundle provides a lightweight event ordering vocabulary via `SequenceAware` and
`AggregateSequenceStamp`, without requiring full event sourcing. Events that carry
per-aggregate sequence metadata are automatically stamped during dispatch, allowing
consumers to detect gaps, enforce ordering, or build projections from the envelope.

## How it works

`SequenceStampDecider` runs in the stamp pipeline (priority 110) for Event-type
messages. When an event implements `SequenceAware`, the decider reads
`getAggregateId()` and `getSequenceNumber()` and attaches an
`AggregateSequenceStamp`. The stamp's `aggregateType` is set to the event's FQCN.

The stamp is added for every dispatch through `EventBus` (synchronous or
asynchronous) and travels with the envelope, so workers consuming the event from
a transport see it as well. If you pass an `AggregateSequenceStamp` yourself when
dispatching, the decider keeps yours.

Events that do not implement `SequenceAware` pass through the decider unchanged.
Commands and queries are not processed by this decider.

## Usage

Implement both `Event` and `SequenceAware` on your event class. The sequence
number comes from your domain model, typically the aggregate's version after the
change:

```php
<?php

namespace App\Domain\Event;

use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\SequenceAware;

final class OrderShipped implements Event, SequenceAware
{
    public function __construct(
        public readonly string $orderId,
        public readonly int $sequenceNumber,
    ) {
    }

    public function getAggregateId(): string
    {
        return $this->orderId;
    }

    public function getSequenceNumber(): int
    {
        return $this->sequenceNumber;
    }
}
```

Handlers read the stamp from the envelope. Implement `EnvelopeAware` (here with
`EnvelopeAwareTrait`) to receive it:

```php
<?php

namespace App\ReadModel;

use App\Domain\Event\OrderShipped;
use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;
use SomeWork\CqrsBundle\Stamp\AggregateSequenceStamp;

#[AsEventHandler(event: OrderShipped::class)]
final class OrderTimelineProjector implements EnvelopeAware
{
    use EnvelopeAwareTrait;

    public function __invoke(OrderShipped $event): void
    {
        $stamp = $this->getEnvelope()->last(AggregateSequenceStamp::class);

        if ($stamp instanceof AggregateSequenceStamp) {
            $aggregateType = $stamp->aggregateType;
            $aggregateId = $stamp->aggregateId;
            $sequenceNumber = $stamp->sequenceNumber;

            // Compare $sequenceNumber with the last one stored for this aggregate
            // to skip duplicates or detect gaps.
        }
    }
}
```

## Configuration

```yaml
somework_cqrs:
    sequence:
        enabled: true
```

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `true` | Enables AggregateSequenceStamp auto-attachment for SequenceAware events. When `false`, SequenceStampDecider is not registered in the stamp pipeline. |

The flag decides which services are registered when the container is compiled,
so it must be a boolean and cannot use an `%env()%` parameter.

## AggregateSequenceStamp properties

The stamp (`SomeWork\CqrsBundle\Stamp\AggregateSequenceStamp`) exposes three
`public readonly` properties:

| Property | Type | Description |
|----------|------|-------------|
| `aggregateId` | `string` | The aggregate identifier returned by `SequenceAware::getAggregateId()`. Must be non-empty; an empty string throws `InvalidArgumentException` at construction time. |
| `sequenceNumber` | `int` | The sequence number returned by `SequenceAware::getSequenceNumber()`. Must be non-negative; a negative value throws `InvalidArgumentException` at construction time. |
| `aggregateType` | `string` | The FQCN of the dispatched event class (`$message::class`). Allows consumers to scope ordering per aggregate type. |

## Limitations

- **Ordering is vocabulary only.** The stamp carries ordering metadata but does not
  enforce processing order. Consumers are responsible for detecting gaps or
  reordering.

- **Events only.** SequenceStampDecider only processes Event-type messages. Commands
  and queries are not affected.

- **No gap detection.** The bundle does not track or detect sequence gaps. Consumers
  must implement gap detection if ordering enforcement is required (e.g., buffering
  out-of-order events until gaps are filled).

- **No sequence generation.** The bundle does not assign sequence numbers; the
  event must provide them.
