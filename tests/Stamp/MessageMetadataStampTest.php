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

    public function test_every_stamp_has_its_own_message_id_which_the_with_methods_keep(): void
    {
        $stamp = new MessageMetadataStamp('flow');
        $other = new MessageMetadataStamp('flow');

        self::assertNotSame($stamp->getMessageId(), $other->getMessageId());
        self::assertSame($stamp->getMessageId(), $stamp->withCausationId('parent')->withCorrelationId('other')->withExtra('k', 1)->getMessageId());
        self::assertSame('given', (new MessageMetadataStamp('flow', [], null, 'given'))->getMessageId());
    }

    public function test_the_first_message_of_a_flow_uses_its_message_id_as_correlation_id(): void
    {
        $stamp = MessageMetadataStamp::createWithRandomCorrelationId();

        self::assertSame($stamp->getMessageId(), $stamp->getCorrelationId());
    }

    public function test_constructor_rejects_an_empty_message_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MessageMetadataStamp('flow', [], null, '');
    }

    public function test_survives_serialization(): void
    {
        $stamp = new MessageMetadataStamp('flow', ['k' => 'v'], 'parent');

        $copy = unserialize(serialize($stamp));
        self::assertInstanceOf(MessageMetadataStamp::class, $copy);
        self::assertSame(
            [$stamp->getMessageId(), 'flow', ['k' => 'v'], 'parent'],
            [$copy->getMessageId(), $copy->getCorrelationId(), $copy->getExtras(), $copy->getCausationId()],
        );
    }

    public function test_reads_a_stamp_serialized_by_0_4(): void
    {
        // serialize(new MessageMetadataStamp('abc', ['k' => 1])) with the class of v0.4.0 (no message id).
        $stamp = unserialize((string) base64_decode('Tzo0NjoiU29tZVdvcmtcQ3Fyc0J1bmRsZVxTdGFtcFxNZXNzYWdlTWV0YWRhdGFTdGFtcCI6Mzp7czo2MToiAFNvbWVXb3JrXENxcnNCdW5kbGVcU3RhbXBcTWVzc2FnZU1ldGFkYXRhU3RhbXAAY29ycmVsYXRpb25JZCI7czozOiJhYmMiO3M6NTQ6IgBTb21lV29ya1xDcXJzQnVuZGxlXFN0YW1wXE1lc3NhZ2VNZXRhZGF0YVN0YW1wAGV4dHJhcyI7YToxOntzOjE6ImsiO2k6MTt9czo1OToiAFNvbWVXb3JrXENxcnNCdW5kbGVcU3RhbXBcTWVzc2FnZU1ldGFkYXRhU3RhbXAAY2F1c2F0aW9uSWQiO047fQ==', true));

        self::assertInstanceOf(MessageMetadataStamp::class, $stamp);
        self::assertSame('abc', $stamp->getCorrelationId());
        self::assertSame('abc', $stamp->getMessageId(), 'The correlation id of 0.4 was unique per message.');
        self::assertSame(['k' => 1], $stamp->getExtras());
        self::assertNull($stamp->getCausationId());
    }
}
