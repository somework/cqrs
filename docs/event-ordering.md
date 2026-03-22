# Event ordering

The bundle provides a lightweight event ordering vocabulary via `SequenceAware` and
`AggregateSequenceStamp`, without requiring full event sourcing. Events that carry
per-aggregate sequence metadata are automatically stamped during dispatch, allowing
consumers to detect gaps, enforce ordering, or build projections from the envelope.

## How it works

`SequenceStampDecider` runs in the stamp pipeline for Event-type messages. When an
event implements `SequenceAware`, the decider reads `getAggregateId()` and
`getSequenceNumber()` and auto-attaches an `AggregateSequenceStamp`. The stamp's
`aggregateType` is set to the event's FQCN.

Events that do not implement `SequenceAware` pass through the decider unchanged.
Commands and queries are not processed by this decider.

## Usage

Implement both `Event` and `SequenceAware` on your event class:

```php
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\SequenceAware;

final class OrderShipped implements Event, SequenceAware
{
    public function __construct(
        public readonly string $orderId,
        public readonly int $sequenceNumber,
    ) {}

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

Consumers read the stamp from the envelope:

```php
use SomeWork\CqrsBundle\Stamp\AggregateSequenceStamp;
use Symfony\Component\Messenger\Envelope;

$stamp = $envelope->last(AggregateSequenceStamp::class);
if ($stamp !== null) {
    $aggregateId = $stamp->aggregateId;
    $sequenceNumber = $stamp->sequenceNumber;
    $aggregateType = $stamp->aggregateType;
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

## AggregateSequenceStamp properties

The stamp exposes three `public readonly` properties:

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
