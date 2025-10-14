<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Stamp;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;

#[CoversClass(MessageMetadataStamp::class)]
final class MessageMetadataStampTest extends TestCase
{
    public function testConstructorRejectsEmptyCorrelationId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Correlation ID cannot be empty.');

        new MessageMetadataStamp('');
    }

    public function testCreateWithRandomCorrelationIdGeneratesNonEmptyIdentifier(): void
    {
        $extras = ['foo' => 'bar'];

        $stamp = MessageMetadataStamp::createWithRandomCorrelationId($extras);
        $anotherStamp = MessageMetadataStamp::createWithRandomCorrelationId();

        self::assertSame($extras, $stamp->getExtras());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $stamp->getCorrelationId());
        self::assertNotSame($stamp->getCorrelationId(), $anotherStamp->getCorrelationId());
    }

    public function testWithCorrelationIdReturnsNewInstanceWithoutMutatingOriginal(): void
    {
        $original = new MessageMetadataStamp('original-id', ['foo' => 'bar']);

        $updated = $original->withCorrelationId('new-id');

        self::assertNotSame($original, $updated);
        self::assertSame('new-id', $updated->getCorrelationId());
        self::assertSame('original-id', $original->getCorrelationId());
        self::assertSame($original->getExtras(), $updated->getExtras());
    }

    public function testWithCorrelationIdValidatesCorrelationId(): void
    {
        $stamp = new MessageMetadataStamp('correlation-id');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Correlation ID cannot be empty.');

        $stamp->withCorrelationId('');
    }

    public function testWithExtraReturnsNewInstanceWithoutMutatingOriginal(): void
    {
        $original = new MessageMetadataStamp('correlation-id', ['foo' => 'bar']);

        $updated = $original->withExtra('baz', 'qux');

        self::assertNotSame($original, $updated);
        self::assertSame([
            'foo' => 'bar',
            'baz' => 'qux',
        ], $updated->getExtras());
        self::assertSame(['foo' => 'bar'], $original->getExtras());
    }

    public function testGetExtrasDoesNotAllowExternalMutation(): void
    {
        $stamp = new MessageMetadataStamp('correlation-id', ['foo' => 'bar']);

        $extras = $stamp->getExtras();
        $extras['foo'] = 'baz';

        self::assertSame('bar', $stamp->getExtras()['foo']);
    }
}
