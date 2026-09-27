<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function json_decode;
use function sort;

#[CoversClass(OutboxMessage::class)]
final class OutboxMessageTest extends TestCase
{
    public function test_exposes_its_properties(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01 10:00:00');
        $message = new OutboxMessage('id-1', 'body', '{}', $createdAt, 'async');

        self::assertSame('id-1', $message->id);
        self::assertSame('body', $message->body);
        self::assertSame('{}', $message->headers);
        self::assertSame($createdAt, $message->createdAt);
        self::assertSame('async', $message->transportName);
        self::assertNull((new OutboxMessage('id-2', 'body', '{}', $createdAt))->transportName);
    }

    public function test_rejects_an_empty_id_or_body(): void
    {
        foreach ([['', 'body'], ['id', '']] as [$id, $body]) {
            try {
                new OutboxMessage($id, $body, '{}', new DateTimeImmutable());
                self::fail('Expected an InvalidArgumentException.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('cannot be empty', $exception->getMessage());
            }
        }
    }

    public function test_from_envelope_round_trips_through_the_serializer(): void
    {
        $serializer = new PhpSerializer();
        $envelope = new Envelope(new CreateTaskCommand('1', 'Write docs'), [new MessageMetadataStamp('corr-1')]);

        $message = OutboxMessage::fromEnvelope($envelope, $serializer, 'async', new DateTimeImmutable('2026-01-01'));
        $decoded = $serializer->decode(['body' => $message->body, 'headers' => json_decode($message->headers, true)]);

        $decodedMessage = $decoded->getMessage();
        self::assertInstanceOf(CreateTaskCommand::class, $decodedMessage);
        self::assertSame(['1', 'Write docs'], [$decodedMessage->id, $decodedMessage->name]);
        self::assertSame('corr-1', $decoded->last(MessageMetadataStamp::class)?->getCorrelationId());
        self::assertSame('async', $message->transportName);
        self::assertSame('2026-01-01 00:00:00', $message->createdAt->format('Y-m-d H:i:s'));
    }

    public function test_generated_ids_are_time_ordered_uuid_v7(): void
    {
        $ids = [];
        for ($i = 0; $i < 200; ++$i) {
            $ids[] = OutboxMessage::fromEnvelope(new Envelope(new \stdClass()), new PhpSerializer())->id;
        }

        $sorted = $ids;
        sort($sorted);

        self::assertSame($sorted, $ids);
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        }
    }
}
