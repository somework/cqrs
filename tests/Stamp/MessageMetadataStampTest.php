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

    public function test_constructor_accepts_causation_id_as_third_parameter(): void
    {
        $stamp = new MessageMetadataStamp('corr-id', ['key' => 'val'], 'cause-id');

        self::assertSame('corr-id', $stamp->getCorrelationId());
        self::assertSame(['key' => 'val'], $stamp->getExtras());
        self::assertSame('cause-id', $stamp->getCausationId());
    }

    public function test_get_causation_id_returns_null_when_not_provided(): void
    {
        $stamp = new MessageMetadataStamp('corr-id');

        self::assertNull($stamp->getCausationId());
    }

    public function test_get_causation_id_returns_value_when_provided(): void
    {
        $stamp = new MessageMetadataStamp('corr-id', [], 'cause-id');

        self::assertSame('cause-id', $stamp->getCausationId());
    }

    public function test_with_causation_id_returns_new_instance_without_mutating_original(): void
    {
        $original = new MessageMetadataStamp('corr-id', ['foo' => 'bar']);

        $updated = $original->withCausationId('cause-id');

        self::assertNotSame($original, $updated);
        self::assertSame('cause-id', $updated->getCausationId());
        self::assertNull($original->getCausationId());
        self::assertSame('corr-id', $updated->getCorrelationId());
        self::assertSame(['foo' => 'bar'], $updated->getExtras());
    }

    public function test_with_correlation_id_preserves_causation_id(): void
    {
        $original = new MessageMetadataStamp('corr-id', [], 'cause-id');

        $updated = $original->withCorrelationId('new-corr');

        self::assertSame('cause-id', $updated->getCausationId());
        self::assertSame('new-corr', $updated->getCorrelationId());
    }

    public function test_with_extra_preserves_causation_id(): void
    {
        $original = new MessageMetadataStamp('corr-id', [], 'cause-id');

        $updated = $original->withExtra('key', 'value');

        self::assertSame('cause-id', $updated->getCausationId());
        self::assertSame(['key' => 'value'], $updated->getExtras());
    }

    public function test_create_with_random_correlation_id_has_null_causation_id(): void
    {
        $stamp = MessageMetadataStamp::createWithRandomCorrelationId();

        self::assertNull($stamp->getCausationId());
    }

    public function test_with_causation_id_on_stamp_that_already_has_causation_id_replaces_it(): void
    {
        $original = new MessageMetadataStamp('corr-id', ['foo' => 'bar'], 'old-cause');

        $updated = $original->withCausationId('new-cause');

        self::assertNotSame($original, $updated);
        self::assertSame('new-cause', $updated->getCausationId());
        self::assertSame('old-cause', $original->getCausationId());
        self::assertSame('corr-id', $updated->getCorrelationId());
        self::assertSame(['foo' => 'bar'], $updated->getExtras());
    }

    public function test_with_causation_id_preserves_correlation_id_and_extras(): void
    {
        $original = new MessageMetadataStamp('corr-id', ['a' => 1, 'b' => 2]);

        $updated = $original->withCausationId('cause-id');

        self::assertSame('corr-id', $updated->getCorrelationId());
        self::assertSame(['a' => 1, 'b' => 2], $updated->getExtras());
        self::assertSame('cause-id', $updated->getCausationId());
    }

    public function test_chained_with_methods_preserve_all_fields(): void
    {
        $stamp = new MessageMetadataStamp('corr-1', ['key' => 'val'], 'cause-1');

        $result = $stamp
            ->withCorrelationId('corr-2')
            ->withExtra('new-key', 'new-val')
            ->withCausationId('cause-2');

        self::assertSame('corr-2', $result->getCorrelationId());
        self::assertSame('cause-2', $result->getCausationId());
        self::assertSame(['key' => 'val', 'new-key' => 'new-val'], $result->getExtras());
    }

    public function test_constructor_rejects_empty_causation_id_string_is_accepted(): void
    {
        $stamp = new MessageMetadataStamp('corr-id', [], '');

        self::assertSame('', $stamp->getCausationId());
    }
}
