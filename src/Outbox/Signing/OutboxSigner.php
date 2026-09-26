<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox\Signing;

use SomeWork\CqrsBundle\Outbox\OutboxMessage;

use function array_map;
use function base64_encode;
use function hash_equals;
use function hash_hmac;
use function pack;
use function rtrim;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtr;

/**
 * Signs outbox rows with HMAC-SHA256 and verifies them before the relay decodes them, so that
 * only rows this application stored reach the serializer (PHP's unserialize() by default).
 *
 * The signature covers the id, the body and the headers (length-prefixed, so their boundaries
 * cannot shift); not the transport name or the dates. It is stored as "v1:" and the base64url
 * MAC, computed with a key derived from the secret for this purpose only.
 *
 * @internal
 */
final class OutboxSigner
{
    public const VERSION = 'v1:';

    private const CONTEXT = 'somework_cqrs.outbox.v1';

    private readonly string $key;

    /** @var list<string> */
    private readonly array $previousKeys;

    /**
     * @param list<string> $previousSecrets Secrets whose signatures are still accepted (after a rotation)
     */
    public function __construct(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] array $previousSecrets = [])
    {
        if ('' === $secret) {
            throw new \LogicException('Outbox signing needs a secret: set "framework.secret" or "somework_cqrs.outbox.signing.secret", or disable "somework_cqrs.outbox.signing".');
        }

        $this->key = self::derive($secret);
        $this->previousKeys = array_map(self::derive(...), array_values(array_filter($previousSecrets, static fn (string $previous): bool => '' !== $previous)));
    }

    public function sign(OutboxMessage $message): string
    {
        return self::VERSION.self::mac($this->key, $message);
    }

    public function verify(OutboxMessage $message): bool
    {
        $signature = $message->signature;
        if (null === $signature || !str_starts_with($signature, self::VERSION)) {
            return false;
        }

        $mac = substr($signature, strlen(self::VERSION));
        foreach ([$this->key, ...$this->previousKeys] as $key) {
            if (hash_equals(self::mac($key, $message), $mac)) {
                return true;
            }
        }

        return false;
    }

    private static function derive(string $secret): string
    {
        return hash_hmac('sha256', self::CONTEXT, $secret, true);
    }

    private static function mac(string $key, OutboxMessage $message): string
    {
        $payload = '';
        foreach ([strtolower($message->id), $message->body, $message->headers] as $field) {
            $payload .= pack('J', strlen($field)).$field;
        }

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $key, true)), '+/', '-_'), '=');
    }
}
