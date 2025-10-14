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
    public function test_constructor_rejects_empty_correlation_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Correlation ID cannot be empty.');

        new MessageMetadataStamp('');
    }

    public function test_create_with_random_correlation_id_generates_non_empty_identifier(): void
    {
        $extras = ['foo' => 'bar'];

        $stamp = MessageMetadataStamp::createWithRandomCorrelationId($extras);
        $anotherStamp = MessageMetadataStamp::createWithRandomCorrelationId();

        self::assertSame($extras, $stamp->getExtras());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $stamp->getCorrelationId());
        self::assertNotSame($stamp->getCorrelationId(), $anotherStamp->getCorrelationId());
    }

    public function test_with_correlation_id_returns_new_instance_without_mutating_original(): void
    {
        $original = new MessageMetadataStamp('original-id', ['foo' => 'bar']);

        $updated = $original->withCorrelationId('new-id');

        self::assertNotSame($original, $updated);
        self::assertSame('new-id', $updated->getCorrelationId());
        self::assertSame('original-id', $original->getCorrelationId());
        self::assertSame($original->getExtras(), $updated->getExtras());
    }

    public function test_with_correlation_id_validates_correlation_id(): void
    {
        $stamp = new MessageMetadataStamp('correlation-id');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Correlation ID cannot be empty.');

        $stamp->withCorrelationId('');
    }

    public function test_with_extra_returns_new_instance_without_mutating_original(): void
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

    public function test_get_extras_does_not_allow_external_mutation(): void
    {
        $stamp = new MessageMetadataStamp('correlation-id', ['foo' => 'bar']);

        $extras = $stamp->getExtras();
        $extras['foo'] = 'baz';

        self::assertSame('bar', $stamp->getExtras()['foo']);
    }
}
