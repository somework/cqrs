<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

use function bin2hex;
use function is_array;
use function is_string;
use function random_bytes;
use function strrpos;
use function substr;

/**
 * Identifies a message and the flow it belongs to.
 *
 * - The message id identifies this message (a retry keeps it).
 * - The correlation id identifies the flow: a message dispatched while another one is handled
 *   inherits the correlation id of the handled message (with "causation_id" enabled), so every
 *   message of a request shares it. The first message of a flow uses its own message id.
 * - The causation id is the message id of the message whose handler dispatched this one.
 *
 * Create one stamp per dispatch: a stamp passed to several dispatches gives them the same
 * message id.
 *
 * @api
 */
final class MessageMetadataStamp implements StampInterface
{
    /** @var non-empty-string */
    private readonly string $correlationId;

    /** @var non-empty-string */
    private readonly string $messageId;

    /**
     * @param array<string, mixed> $extras
     * @param string|null          $messageId Id of this message; null generates a random one
     */
    public function __construct(
        string $correlationId,
        private readonly array $extras = [],
        private readonly ?string $causationId = null,
        ?string $messageId = null,
    ) {
        if ('' === $correlationId) {
            throw new \InvalidArgumentException('Correlation ID cannot be empty.');
        }

        $this->correlationId = $correlationId;

        if ('' === $messageId) {
            throw new \InvalidArgumentException('Message ID cannot be empty.');
        }

        $this->messageId = $messageId ?? self::generateId();
    }

    /**
     * A stamp for the first message of a flow: its correlation id is its message id.
     *
     * @param array<string, mixed> $extras
     */
    public static function createWithRandomCorrelationId(array $extras = []): self
    {
        $id = self::generateId();

        return new self($id, $extras, null, $id);
    }

    /**
     * @return non-empty-string
     */
    public function getMessageId(): string
    {
        return $this->messageId;
    }

    /**
     * @return non-empty-string
     */
    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    /**
     * Message id of the message whose handler dispatched this one.
     */
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
        return new self($this->correlationId, $this->extras, $causationId, $this->messageId);
    }

    public function withCorrelationId(string $correlationId): self
    {
        return new self($correlationId, $this->extras, $this->causationId, $this->messageId);
    }

    public function withExtra(string $key, mixed $value): self
    {
        $extras = $this->extras;
        $extras[$key] = $value;

        return new self($this->correlationId, $extras, $this->causationId, $this->messageId);
    }

    /**
     * @return array{correlationId: string, extras: array<string, mixed>, causationId: ?string, messageId: string}
     */
    public function __serialize(): array
    {
        return [
            'correlationId' => $this->correlationId,
            'extras' => $this->extras,
            'causationId' => $this->causationId,
            'messageId' => $this->messageId,
        ];
    }

    /**
     * Also reads stamps serialized by 0.4 (private property names, no message id): their
     * correlation id was unique per message, so it becomes the message id.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $values = [];
        foreach ($data as $key => $value) {
            $values[substr($key, (int) strrpos("\0".$key, "\0"))] = $value;
        }

        $correlationId = $values['correlationId'] ?? null;
        $extras = $values['extras'] ?? [];
        $causationId = $values['causationId'] ?? null;
        $messageId = $values['messageId'] ?? $correlationId;
        if (!is_string($correlationId) || '' === $correlationId || !is_array($extras) || (null !== $causationId && !is_string($causationId)) || !is_string($messageId) || '' === $messageId) {
            throw new \UnexpectedValueException('Invalid serialized MessageMetadataStamp.');
        }

        $this->correlationId = $correlationId;
        $this->extras = $extras;
        $this->causationId = $causationId;
        $this->messageId = $messageId;
    }

    /**
     * @return non-empty-string
     */
    private static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
