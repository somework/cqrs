<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;

use function sprintf;

#[CoversClass(OutboxMessage::class)]
final class OutboxMessageTest extends TestCase
{
    public function test_construct_with_all_properties(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01');

        $message = new OutboxMessage(
            id: 'msg-1',
            body: '{"data":1}',
            headers: '{"type":"App\\\\Cmd"}',
            createdAt: $createdAt,
            transportName: 'async',
        );

        self::assertSame('msg-1', $message->id);
        self::assertSame('{"data":1}', $message->body);
        self::assertSame('{"type":"App\\\\Cmd"}', $message->headers);
        self::assertSame($createdAt, $message->createdAt);
        self::assertSame('async', $message->transportName);
    }

    public function test_construct_with_default_transport_name(): void
    {
        $message = new OutboxMessage(
            id: 'msg-2',
            body: '{}',
            headers: '{}',
            createdAt: new \DateTimeImmutable(),
        );

        self::assertNull($message->transportName);
    }

    public function test_is_immutable(): void
    {
        $reflection = new ReflectionClass(OutboxMessage::class);

        foreach ($reflection->getProperties() as $property) {
            self::assertTrue(
                $property->isReadOnly(),
                sprintf('Property "%s" must be readonly.', $property->getName()),
            );
        }
    }

    public function test_interface_exists(): void
    {
        self::assertTrue(interface_exists(OutboxStorage::class));
    }

    public function test_interface_declares_store(): void
    {
        $reflection = new ReflectionClass(OutboxStorage::class);
        $method = $reflection->getMethod('store');
        $parameters = $method->getParameters();

        self::assertCount(1, $parameters);
        self::assertSame('message', $parameters[0]->getName());
        $paramType = $parameters[0]->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $paramType);
        self::assertSame(OutboxMessage::class, $paramType->getName());
    }

    public function test_interface_declares_fetch_unpublished(): void
    {
        $reflection = new ReflectionClass(OutboxStorage::class);
        $method = $reflection->getMethod('fetchUnpublished');
        $parameters = $method->getParameters();

        self::assertCount(1, $parameters);
        self::assertSame('limit', $parameters[0]->getName());
        $paramType = $parameters[0]->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $paramType);
        self::assertSame('int', $paramType->getName());
    }

    public function test_interface_declares_mark_published(): void
    {
        $reflection = new ReflectionClass(OutboxStorage::class);
        $method = $reflection->getMethod('markPublished');
        $parameters = $method->getParameters();

        self::assertCount(1, $parameters);
        self::assertSame('id', $parameters[0]->getName());
        $paramType = $parameters[0]->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $paramType);
        self::assertSame('string', $paramType->getName());
    }

    public function test_construct_with_empty_body_and_headers(): void
    {
        $message = new OutboxMessage(
            id: 'msg-empty',
            body: '',
            headers: '',
            createdAt: new \DateTimeImmutable(),
        );

        self::assertSame('', $message->body);
        self::assertSame('', $message->headers);
    }

    public function test_construct_preserves_exact_json_content(): void
    {
        $body = '{"nested":{"key":"value","arr":[1,2,3]},"unicode":"ñ"}';
        $headers = '{"type":"App\\\\Event\\\\UserCreated","Content-Type":"application/json"}';

        $message = new OutboxMessage(
            id: 'msg-json',
            body: $body,
            headers: $headers,
            createdAt: new \DateTimeImmutable(),
            transportName: 'async',
        );

        self::assertSame($body, $message->body);
        self::assertSame($headers, $message->headers);
    }

    public function test_two_messages_with_same_data_are_independent(): void
    {
        $createdAt = new \DateTimeImmutable();

        $msg1 = new OutboxMessage('id-1', '{}', '{}', $createdAt, 'async');
        $msg2 = new OutboxMessage('id-2', '{}', '{}', $createdAt, 'async');

        self::assertNotSame($msg1, $msg2);
        self::assertSame('id-1', $msg1->id);
        self::assertSame('id-2', $msg2->id);
    }

    public function test_is_final_class(): void
    {
        $reflection = new ReflectionClass(OutboxMessage::class);
        self::assertTrue($reflection->isFinal());
    }

    public function test_has_exactly_five_properties(): void
    {
        $reflection = new ReflectionClass(OutboxMessage::class);
        self::assertCount(5, $reflection->getProperties());
    }

    public function test_interface_return_types(): void
    {
        $reflection = new ReflectionClass(OutboxStorage::class);

        $store = $reflection->getMethod('store');
        $storeReturnType = $store->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $storeReturnType);
        self::assertSame('void', $storeReturnType->getName());

        $fetch = $reflection->getMethod('fetchUnpublished');
        $fetchReturnType = $fetch->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $fetchReturnType);
        self::assertSame('array', $fetchReturnType->getName());

        $mark = $reflection->getMethod('markPublished');
        $markReturnType = $mark->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $markReturnType);
        self::assertSame('void', $markReturnType->getName());
    }

    public function test_interface_declares_exactly_three_methods(): void
    {
        $reflection = new ReflectionClass(OutboxStorage::class);

        self::assertCount(3, $reflection->getMethods());
    }

    public function test_created_at_property_type_is_datetime_immutable(): void
    {
        $reflection = new ReflectionClass(OutboxMessage::class);
        $property = $reflection->getProperty('createdAt');

        $propertyType = $property->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $propertyType);
        self::assertSame(\DateTimeImmutable::class, $propertyType->getName());
    }

    public function test_transport_name_property_is_nullable(): void
    {
        $reflection = new ReflectionClass(OutboxMessage::class);
        $property = $reflection->getProperty('transportName');

        self::assertTrue($property->getType()?->allowsNull());
    }

    public function test_fetch_unpublished_limit_parameter_has_no_default(): void
    {
        $reflection = new ReflectionClass(OutboxStorage::class);
        $method = $reflection->getMethod('fetchUnpublished');
        $param = $method->getParameters()[0];

        self::assertFalse($param->isDefaultValueAvailable());
    }
}
