<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\Support\StampDecider;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function array_map;

#[CoversClass(OutboxWriter::class)]
final class OutboxWriterTest extends TestCase
{
    private InMemoryOutboxStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryOutboxStorage();
    }

    public function test_stores_the_message_with_its_stamps_for_the_given_transport(): void
    {
        $rows = (new OutboxWriter($this->storage, new PhpSerializer(), $this->transports(['async'])))
            ->store(new CreateTaskCommand('1', 'a'), 'orders', new DelayStamp(5000));

        self::assertCount(1, $rows);
        self::assertSame('orders', $rows[0]->transportName, 'A given transport wins over the configuration.');
        self::assertSame([$rows[0]], $this->storage->fetchUnpublished(10));

        $envelope = (new PhpSerializer())->decode(['body' => $rows[0]->body]);
        $decoded = $envelope->getMessage();
        self::assertInstanceOf(CreateTaskCommand::class, $decoded);
        self::assertSame('1', $decoded->id);
        self::assertSame(5000, $envelope->last(DelayStamp::class)?->getDelay());
    }

    public function test_stores_one_row_per_configured_transport(): void
    {
        // So a failing transport is retried alone, instead of sending the message to the others again.
        $rows = (new OutboxWriter($this->storage, new PhpSerializer(), $this->transports(['async', 'audit'])))->store(new CreateTaskCommand('1', 'a'));

        self::assertSame(['async', 'audit'], array_map(static fn ($row): ?string => $row->transportName, $rows));
        self::assertCount(2, $this->storage->fetchUnpublished(10));
    }

    public function test_without_a_configured_transport_the_row_follows_the_routing(): void
    {
        self::assertNull((new OutboxWriter($this->storage, new PhpSerializer(), $this->transports([])))->store(new CreateTaskCommand('1', 'a'))[0]->transportName);
        self::assertNull((new OutboxWriter($this->storage, new PhpSerializer()))->store(new CreateTaskCommand('2', 'b'))[0]->transportName);
    }

    /**
     * @param list<string> $names
     */
    private function transports(array $names): StampDecider
    {
        return new class($names) implements StampDecider {
            /**
             * @param list<string> $names
             */
            public function __construct(private readonly array $names)
            {
            }

            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                TestCase::assertSame(DispatchMode::ASYNC, $mode);

                return [] === $this->names ? $stamps : [...$stamps, new TransportNamesStamp($this->names)];
            }
        };
    }
}
