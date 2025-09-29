<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries metadata such as correlation identifiers for Messenger messages.
 */
final class MessageMetadataStamp implements StampInterface
{
    /**
     * @param non-empty-string $correlationId
     * @param array<string, mixed> $extras
     */
    public function __construct(
        private readonly string $correlationId,
        private readonly array $extras = [],
    ) {
        if ('' === $this->correlationId) {
            throw new \InvalidArgumentException('Correlation ID cannot be empty.');
        }
    }

    public static function createWithRandomCorrelationId(array $extras = []): self
    {
        return new self(self::generateCorrelationId(), $extras);
    }

    /**
     * @return non-empty-string
     */
    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtras(): array
    {
        return $this->extras;
    }

    public function withCorrelationId(string $correlationId): self
    {
        return new self($correlationId, $this->extras);
    }

    public function withExtra(string $key, mixed $value): self
    {
        $extras = $this->extras;
        $extras[$key] = $value;

        return new self($this->correlationId, $extras);
    }

    /**
     * @return non-empty-string
     */
    private static function generateCorrelationId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
