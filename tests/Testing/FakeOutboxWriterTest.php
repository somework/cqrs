<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Testing;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Exception\OutboxRequiresTransactionException;
use SomeWork\CqrsBundle\Testing\CqrsAssertionsTrait;
use SomeWork\CqrsBundle\Testing\FakeOutboxWriter;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

#[CoversClass(FakeOutboxWriter::class)]
final class FakeOutboxWriterTest extends TestCase
{
    use CqrsAssertionsTrait;

    public function test_records_stored_messages_for_the_outbox_assertions(): void
    {
        $writer = new FakeOutboxWriter();

        $rows = $writer->store(new CreateTaskCommand('1', 'a'), 'orders', new DelayStamp(5));

        self::assertCount(1, $rows);
        self::assertSame('orders', $rows[0]->transportName);
        self::assertInstanceOf(CreateTaskCommand::class, (new PhpSerializer())->decode(['body' => $rows[0]->body])->getMessage());
        self::assertSame($rows, $writer->getStoredRows());

        $dispatched = $writer->getDispatched();
        self::assertSame(DispatchMode::OUTBOX, $dispatched[0]->mode);
        self::assertSame(['orders'], array_values(array_filter($dispatched[0]->stamps, static fn ($stamp): bool => $stamp instanceof TransportNamesStamp))[0]->getTransportNames());

        self::assertStoredInOutbox($writer, CreateTaskCommand::class, static fn (CreateTaskCommand $command): bool => '1' === $command->id);
        self::assertNotStoredInOutbox($writer, CreateTaskCommand::class, static fn (CreateTaskCommand $command): bool => '2' === $command->id);
    }

    public function test_throws_what_it_was_told_to_and_resets(): void
    {
        $writer = new FakeOutboxWriter();
        $writer->willThrow(new OutboxRequiresTransactionException(CreateTaskCommand::class));

        try {
            $writer->store(new CreateTaskCommand('1', 'a'));
            self::fail('Expected the configured exception.');
        } catch (OutboxRequiresTransactionException) {
        }
        self::assertCount(1, $writer->getDispatched(), 'Recorded first, as the fake buses do.');

        $writer->reset();
        self::assertSame([], $writer->getDispatched());
        self::assertSame([], $writer->getStoredRows());
        self::assertCount(1, $writer->store(new CreateTaskCommand('2', 'b')));

        $this->expectException(AssertionFailedError::class);
        self::assertStoredInOutbox($writer, CreateTaskCommand::class, static fn (CreateTaskCommand $command): bool => '1' === $command->id);
    }
}
