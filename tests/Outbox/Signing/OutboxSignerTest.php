<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox\Signing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\Signing\OutboxSigner;
use SomeWork\CqrsBundle\Outbox\Signing\SigningOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;

use function strlen;
use function strtoupper;

#[CoversClass(OutboxSigner::class)]
#[CoversClass(SigningOutboxStorage::class)]
final class OutboxSignerTest extends TestCase
{
    private const ID = '0199a000-0000-7000-8000-00000000000a';

    public function test_a_signed_message_verifies(): void
    {
        $signer = new OutboxSigner('secret');
        $signature = $signer->sign(self::message());

        self::assertStringStartsWith('v1:', $signature);
        self::assertSame(46, strlen($signature), 'Fits the signature column.');
        self::assertTrue($signer->verify(self::message(signature: $signature)));
        self::assertTrue($signer->verify(self::message(id: strtoupper(self::ID), signature: $signature)), 'Ids are compared in lower case.');
    }

    public function test_a_changed_row_does_not_verify(): void
    {
        $signer = new OutboxSigner('secret');
        $signature = $signer->sign(self::message());

        self::assertFalse($signer->verify(self::message(body: 'O:8:"stdClass":0:{}', signature: $signature)));
        self::assertFalse($signer->verify(self::message(headers: '{"type":"Other"}', signature: $signature)));
        self::assertFalse($signer->verify(self::message(id: '0199a000-0000-7000-8000-00000000000b', signature: $signature)));
        self::assertFalse($signer->verify(self::message()), 'Unsigned.');
        self::assertFalse($signer->verify(self::message(signature: 'v2:'.substr($signature, 3))));
    }

    public function test_the_fields_cannot_shift_into_each_other(): void
    {
        $signer = new OutboxSigner('secret');

        self::assertFalse($signer->verify(self::message(body: 'ab', headers: 'c', signature: $signer->sign(self::message(body: 'a', headers: 'bc')))));
    }

    public function test_previous_secrets_are_accepted_until_they_are_removed(): void
    {
        $signature = (new OutboxSigner('old'))->sign(self::message());

        self::assertTrue((new OutboxSigner('new', ['old']))->verify(self::message(signature: $signature)));
        self::assertFalse((new OutboxSigner('new'))->verify(self::message(signature: $signature)));
        self::assertNotSame($signature, (new OutboxSigner('new', ['old']))->sign(self::message()), 'New rows are signed with the current secret.');
    }

    public function test_an_empty_secret_is_rejected(): void
    {
        $this->expectExceptionMessage('Outbox signing needs a secret');

        new OutboxSigner('');
    }

    public function test_the_storage_decorator_signs_what_it_stores(): void
    {
        $inner = new InMemoryOutboxStorage();
        $signer = new OutboxSigner('secret');

        (new SigningOutboxStorage($inner, $signer))->store(self::message());

        self::assertTrue($signer->verify($inner->message(self::ID)));
    }

    private static function message(string $id = self::ID, string $body = 'body', string $headers = '{}', ?string $signature = null): OutboxMessage
    {
        return new OutboxMessage($id, $body, $headers, new DateTimeImmutable('2026-01-01 10:00:00'), 'async', signature: $signature);
    }
}
