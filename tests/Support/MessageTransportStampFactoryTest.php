<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use InvalidArgumentException;
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

    public function test_throws_on_unknown_stamp_type(): void
    {
        $factory = new MessageTransportStampFactory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown transport stamp type "nonexistent".');

        $factory->create('nonexistent', ['default']);
    }

    public function test_creates_stamp_with_multiple_transports(): void
    {
        $factory = new MessageTransportStampFactory();

        $stamp = $factory->create(MessageTransportStampFactory::TYPE_TRANSPORT_NAMES, ['async', 'audit']);

        self::assertInstanceOf(TransportNamesStamp::class, $stamp);
        self::assertSame(['async', 'audit'], $stamp->getTransportNames());
    }
}
