<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\OutboxRelayCommand;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

#[CoversClass(OutboxRelayCommand::class)]
final class OutboxRelayCommandTest extends TestCase
{
    private MockObject&OutboxStorage $outboxStorage;

    private MockObject&SerializerInterface $serializer;

    private MockObject&MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outboxStorage = $this->createMock(OutboxStorage::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
    }

    public function test_no_messages_outputs_info(): void
    {
        $this->outboxStorage
            ->expects(self::once())
            ->method('fetchUnpublished')
            ->with(100)
            ->willReturn([]);

        $tester = $this->executeTester();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No unpublished messages found.', $tester->getDisplay());
    }

    public function test_relays_messages_in_order(): void
    {
        $message1 = new OutboxMessage(
            id: 'id-1',
            body: '{"data":"one"}',
            headers: '{"type":"App\\\\Command\\\\Foo"}',
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:00'),
            transportName: 'async',
        );
        $message2 = new OutboxMessage(
            id: 'id-2',
            body: '{"data":"two"}',
            headers: '{"type":"App\\\\Command\\\\Bar"}',
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:01'),
            transportName: 'async',
        );

        $this->outboxStorage
            ->expects(self::once())
            ->method('fetchUnpublished')
            ->with(100)
            ->willReturn([$message1, $message2]);

        $envelope1 = new Envelope(new \stdClass());
        $envelope2 = new Envelope(new \stdClass());

        $decodeCall = 0;
        $this->serializer
            ->expects(self::exactly(2))
            ->method('decode')
            ->willReturnCallback(static function (array $encodedEnvelope) use (&$decodeCall, $envelope1, $envelope2): Envelope {
                ++$decodeCall;

                return 1 === $decodeCall ? $envelope1 : $envelope2;
            });

        $dispatchOrder = [];
        $this->messageBus
            ->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (Envelope $envelope) use (&$dispatchOrder): Envelope {
                $dispatchOrder[] = $envelope;

                return $envelope;
            });

        $publishOrder = [];
        $this->outboxStorage
            ->expects(self::exactly(2))
            ->method('markPublished')
            ->willReturnCallback(static function (string $id) use (&$publishOrder): void {
                $publishOrder[] = $id;
            });

        $tester = $this->executeTester();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Relayed 2 message(s)', $tester->getDisplay());
        self::assertSame([$envelope1, $envelope2], $dispatchOrder);
        self::assertSame(['id-1', 'id-2'], $publishOrder);
    }

    public function test_limit_option_passed_to_storage(): void
    {
        $this->outboxStorage
            ->expects(self::once())
            ->method('fetchUnpublished')
            ->with(50)
            ->willReturn([]);

        $tester = $this->executeTester(['--limit' => '50']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function test_default_limit_is_100(): void
    {
        $this->outboxStorage
            ->expects(self::once())
            ->method('fetchUnpublished')
            ->with(100)
            ->willReturn([]);

        $this->executeTester();
    }

    public function test_dispatch_failure_continues_and_returns_failure(): void
    {
        $message1 = new OutboxMessage(
            id: 'fail-1',
            body: '{"data":"one"}',
            headers: '{"type":"App\\\\Command\\\\Foo"}',
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:00'),
        );
        $message2 = new OutboxMessage(
            id: 'ok-2',
            body: '{"data":"two"}',
            headers: '{"type":"App\\\\Command\\\\Bar"}',
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:01'),
        );

        $this->outboxStorage
            ->expects(self::once())
            ->method('fetchUnpublished')
            ->willReturn([$message1, $message2]);

        $envelope1 = new Envelope(new \stdClass());
        $envelope2 = new Envelope(new \stdClass());

        $decodeCall = 0;
        $this->serializer
            ->expects(self::exactly(2))
            ->method('decode')
            ->willReturnCallback(static function () use (&$decodeCall, $envelope1, $envelope2): Envelope {
                ++$decodeCall;

                return 1 === $decodeCall ? $envelope1 : $envelope2;
            });

        $dispatchCall = 0;
        $this->messageBus
            ->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (Envelope $envelope) use (&$dispatchCall): Envelope {
                ++$dispatchCall;
                if (1 === $dispatchCall) {
                    throw new \RuntimeException('Transport failure');
                }

                return $envelope;
            });

        $publishedIds = [];
        $this->outboxStorage
            ->expects(self::once())
            ->method('markPublished')
            ->with('ok-2')
            ->willReturnCallback(static function (string $id) use (&$publishedIds): void {
                $publishedIds[] = $id;
            });

        $tester = $this->executeTester();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Transport failure', $tester->getDisplay());
        self::assertSame(['ok-2'], $publishedIds);
    }

    public function test_serializer_decode_failure_continues_and_returns_failure(): void
    {
        $message1 = new OutboxMessage(
            id: 'decode-fail',
            body: 'invalid-json',
            headers: '{}',
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:00'),
        );
        $message2 = new OutboxMessage(
            id: 'decode-ok',
            body: '{"data":"two"}',
            headers: '{"type":"App\\\\Command\\\\Bar"}',
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:01'),
        );

        $this->outboxStorage
            ->expects(self::once())
            ->method('fetchUnpublished')
            ->willReturn([$message1, $message2]);

        $envelope = new Envelope(new \stdClass());

        $decodeCall = 0;
        $this->serializer
            ->expects(self::exactly(2))
            ->method('decode')
            ->willReturnCallback(static function () use (&$decodeCall, $envelope): Envelope {
                ++$decodeCall;
                if (1 === $decodeCall) {
                    throw new \RuntimeException('Decode error');
                }

                return $envelope;
            });

        $this->messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->willReturn($envelope);

        $this->outboxStorage
            ->expects(self::once())
            ->method('markPublished')
            ->with('decode-ok');

        $tester = $this->executeTester();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Decode error', $tester->getDisplay());
        self::assertStringContainsString('Relayed 1 message(s)', $tester->getDisplay());
    }

    public function test_all_messages_fail_returns_failure_with_zero_relayed(): void
    {
        $message = new OutboxMessage(
            id: 'all-fail',
            body: '{}',
            headers: '{}',
            createdAt: new \DateTimeImmutable(),
        );

        $this->outboxStorage
            ->expects(self::once())
            ->method('fetchUnpublished')
            ->willReturn([$message]);

        $this->serializer
            ->method('decode')
            ->willThrowException(new \RuntimeException('Total failure'));

        $this->outboxStorage
            ->expects(self::never())
            ->method('markPublished');

        $tester = $this->executeTester();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Total failure', $tester->getDisplay());
        self::assertStringNotContainsString('Relayed', $tester->getDisplay());
    }

    public function test_command_name_is_correct(): void
    {
        $command = new OutboxRelayCommand(
            $this->outboxStorage,
            $this->serializer,
            $this->messageBus,
        );

        self::assertSame('somework:cqrs:outbox:relay', $command->getName());
    }

    public function test_command_has_limit_option(): void
    {
        $command = new OutboxRelayCommand(
            $this->outboxStorage,
            $this->serializer,
            $this->messageBus,
        );

        $definition = $command->getDefinition();
        self::assertTrue($definition->hasOption('limit'));

        $option = $definition->getOption('limit');
        self::assertSame('l', $option->getShortcut());
        self::assertSame('100', $option->getDefault());
        self::assertTrue($option->isValueRequired());
    }

    public function test_mark_published_failure_does_not_stop_processing(): void
    {
        $message1 = new OutboxMessage(
            id: 'mark-fail',
            body: '{"data":"one"}',
            headers: '{"type":"Cmd"}',
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:00'),
        );
        $message2 = new OutboxMessage(
            id: 'mark-ok',
            body: '{"data":"two"}',
            headers: '{"type":"Cmd2"}',
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:01'),
        );

        $this->outboxStorage
            ->method('fetchUnpublished')
            ->willReturn([$message1, $message2]);

        $envelope = new Envelope(new \stdClass());

        $this->serializer
            ->method('decode')
            ->willReturn($envelope);

        $this->messageBus
            ->method('dispatch')
            ->willReturn($envelope);

        $markCall = 0;
        $this->outboxStorage
            ->expects(self::exactly(2))
            ->method('markPublished')
            ->willReturnCallback(static function () use (&$markCall): void {
                ++$markCall;
                if (1 === $markCall) {
                    throw new \RuntimeException('Mark failed');
                }
            });

        $tester = $this->executeTester();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function test_single_message_success(): void
    {
        $message = new OutboxMessage(
            id: 'single-1',
            body: '{"payload":"test"}',
            headers: '{"type":"App\\\\Cmd"}',
            createdAt: new \DateTimeImmutable(),
            transportName: 'async',
        );

        $this->outboxStorage
            ->method('fetchUnpublished')
            ->willReturn([$message]);

        $envelope = new Envelope(new \stdClass());

        $this->serializer
            ->expects(self::once())
            ->method('decode')
            ->with([
                'body' => '{"payload":"test"}',
                'headers' => ['type' => 'App\\Cmd'],
            ])
            ->willReturn($envelope);

        $this->messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with($envelope)
            ->willReturn($envelope);

        $this->outboxStorage
            ->expects(self::once())
            ->method('markPublished')
            ->with('single-1');

        $tester = $this->executeTester();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Relayed 1 message(s)', $tester->getDisplay());
    }

    public function test_headers_are_json_decoded_before_passing_to_serializer(): void
    {
        $headers = '{"type":"App\\\\Event\\\\OrderPlaced","Content-Type":"application/json","X-Custom":"value"}';

        $message = new OutboxMessage(
            id: 'json-headers-1',
            body: '{"orderId":"abc"}',
            headers: $headers,
            createdAt: new \DateTimeImmutable(),
            transportName: 'async',
        );

        $this->outboxStorage
            ->method('fetchUnpublished')
            ->willReturn([$message]);

        $envelope = new Envelope(new \stdClass());

        $this->serializer
            ->expects(self::once())
            ->method('decode')
            ->with(self::callback(static function (array $encodedEnvelope): bool {
                // Headers must be a decoded array, not a JSON string
                self::assertIsArray($encodedEnvelope['headers']);
                self::assertSame('App\\Event\\OrderPlaced', $encodedEnvelope['headers']['type']);
                self::assertSame('application/json', $encodedEnvelope['headers']['Content-Type']);
                self::assertSame('value', $encodedEnvelope['headers']['X-Custom']);

                // Body remains as-is (string)
                self::assertSame('{"orderId":"abc"}', $encodedEnvelope['body']);

                return true;
            }))
            ->willReturn($envelope);

        $this->messageBus
            ->method('dispatch')
            ->willReturn($envelope);

        $this->outboxStorage
            ->expects(self::once())
            ->method('markPublished')
            ->with('json-headers-1');

        $tester = $this->executeTester();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function test_empty_headers_json_decoded_to_empty_array(): void
    {
        $message = new OutboxMessage(
            id: 'empty-headers-1',
            body: '{"data":"test"}',
            headers: '{}',
            createdAt: new \DateTimeImmutable(),
        );

        $this->outboxStorage
            ->method('fetchUnpublished')
            ->willReturn([$message]);

        $envelope = new Envelope(new \stdClass());

        $this->serializer
            ->expects(self::once())
            ->method('decode')
            ->with(self::callback(static function (array $encodedEnvelope): bool {
                self::assertSame([], $encodedEnvelope['headers']);

                return true;
            }))
            ->willReturn($envelope);

        $this->messageBus
            ->method('dispatch')
            ->willReturn($envelope);

        $tester = $this->executeTester();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function test_command_description_is_set(): void
    {
        $command = new OutboxRelayCommand(
            $this->outboxStorage,
            $this->serializer,
            $this->messageBus,
        );

        self::assertSame('Relay unpublished outbox messages to their transports.', $command->getDescription());
    }

    /**
     * @param array<string, string> $input
     */
    private function executeTester(array $input = []): CommandTester
    {
        $command = new OutboxRelayCommand(
            $this->outboxStorage,
            $this->serializer,
            $this->messageBus,
        );

        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }
}
