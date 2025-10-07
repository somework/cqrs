<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use LogicException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Support\MessageTransportStampFactory;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class MessageTransportStampFactoryTest extends TestCase
{
    public function test_creates_transport_names_stamp(): void
    {
        $factory = new MessageTransportStampFactory();

        $stamp = $factory->create(MessageTransportStampFactory::TYPE_TRANSPORT_NAMES, ['default']);

        self::assertInstanceOf(TransportNamesStamp::class, $stamp);
        self::assertSame(['default'], $stamp->getTransportNames());
    }

    public function test_throws_when_send_message_stamp_missing(): void
    {
        if (class_exists(MessageTransportStampFactory::SEND_MESSAGE_TO_TRANSPORTS_STAMP_CLASS)) {
            self::markTestSkipped('SendMessageToTransportsStamp is available, cannot assert exception.');
        }

        $factory = new MessageTransportStampFactory();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SendMessageToTransportsStamp');

        $factory->create(MessageTransportStampFactory::TYPE_SEND_MESSAGE, ['async']);
    }

    #[RunInSeparateProcess]
    public function test_creates_send_message_stamp_when_available(): void
    {
        require_once __DIR__.'/../Fixture/Messenger/SendMessageToTransportsStampStub.php';

        $factory = new MessageTransportStampFactory();

        $stamp = $factory->create(MessageTransportStampFactory::TYPE_SEND_MESSAGE, ['async']);

        $class = MessageTransportStampFactory::SEND_MESSAGE_TO_TRANSPORTS_STAMP_CLASS;
        self::assertInstanceOf($class, $stamp);

        $getter = method_exists($stamp, 'getTransportNames') ? 'getTransportNames' : 'getTransports';

        /** @var callable(): array $callable */
        $callable = [$stamp, $getter];

        self::assertSame(['async'], $callable());
    }
}
