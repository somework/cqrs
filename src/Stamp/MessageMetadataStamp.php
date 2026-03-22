<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries metadata such as correlation identifiers for Messenger messages.
 *
 * @api
 */
final class MessageMetadataStamp implements StampInterface
{
    /**
     * @param array<string, mixed> $extras
     */
    public function __construct(
        private readonly string $correlationId,
        private readonly array $extras = [],
        private readonly ?string $causationId = null,
    ) {
        if ('' === $this->correlationId) {
            throw new \InvalidArgumentException('Correlation ID cannot be empty.');
        }
    }

    /** @param array<string, mixed> $extras */
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

    public function getCausationId(): ?string
    {
        return $this->causationId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtras(): array
    {
        return $this->extras;
    }

    public function withCausationId(string $causationId): self
    {
        return new self($this->correlationId, $this->extras, $causationId);
    }

    public function withCorrelationId(string $correlationId): self
    {
        return new self($correlationId, $this->extras, $this->causationId);
    }

    public function withExtra(string $key, mixed $value): self
    {
        $extras = $this->extras;
        $extras[$key] = $value;

        return new self($this->correlationId, $extras, $this->causationId);
    }

    /**
     * @return non-empty-string
     */
    private static function generateCorrelationId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
