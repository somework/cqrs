<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use Symfony\Component\Messenger\Envelope;

use function array_keys;
use function array_unique;
use function array_values;
use function base64_decode;
use function ctype_digit;
use function ctype_xdigit;
use function is_string;
use function preg_match_all;
use function str_ends_with;
use function stripslashes;
use function strlen;
use function strpos;
use function substr;

/**
 * Reads a body of Messenger's PHP serializer (an escaped serialize() of the envelope, or its
 * base64) as text, without unserializing it: the class of the message and every class the body
 * would instantiate, including those nested in stamps and properties. Strings are skipped by
 * their length, so class names written inside a string are not mistaken for objects.
 *
 * @internal
 */
final class SerializedBody
{
    private const MAX_DEPTH = 512;

    /** @var array<string, true> */
    private array $classes = [];

    private function __construct(private readonly string $data, private int $position = 0)
    {
    }

    /**
     * The class of the message (null when the body is not a serialized envelope) and every class the
     * body instantiates. For a body that cannot be read as a serialized value (another serializer,
     * or a malformed payload), every object, custom object or enum token found in its text.
     *
     * @return array{messageClass: string|null, classes: list<string>}
     */
    public static function inspect(string $body): array
    {
        // As PhpSerializer::decode() reads it.
        if (!str_ends_with($body, '}')) {
            $decoded = base64_decode($body, true);
            $body = false === $decoded ? $body : $decoded;
        }
        $data = stripslashes($body);

        $reader = new self($data);
        try {
            $value = $reader->value(0);
        } catch (\UnexpectedValueException) {
            preg_match_all('/[OCE]:\+?\d+:"([^"]*)"/', $data, $matches);

            return ['messageClass' => null, 'classes' => array_values(array_unique($matches[1]))];
        }

        $messageClass = null;
        if (Envelope::class === $value['class'] && is_string($value['message'])) {
            $messageClass = $value['message'];
        }

        return ['messageClass' => $messageClass, 'classes' => array_keys($reader->classes)];
    }

    /**
     * Reads one value; for an object, its class and, for an envelope, the class of its message.
     *
     * @return array{class: string|null, message: string|null}
     */
    private function value(int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \UnexpectedValueException('Too deep.');
        }

        $type = $this->data[$this->position] ?? '';
        ++$this->position;
        $none = ['class' => null, 'message' => null];

        switch ($type) {
            case 'N':
                $this->expect(';');

                return $none;
            case 'b':
            case 'i':
            case 'd':
            case 'r':
            case 'R':
                $this->expect(':');
                $this->until(';');

                return $none;
            case 's':
                $this->expect(':');
                $this->quoted();
                $this->expect(';');

                return $none;
            case 'S':
                $this->expect(':');
                $this->escaped($this->length());
                $this->expect(';');

                return $none;
            case 'E':
                $this->expect(':');
                $enum = $this->quoted();
                $this->expect(';');
                $separator = strpos($enum, ':');
                $this->classes[false === $separator ? $enum : substr($enum, 0, $separator)] = true;

                return $none;
            case 'a':
                $this->expect(':');
                $count = $this->number(false);
                $this->expect(':{');
                for ($i = 0; $i < $count; ++$i) {
                    $this->key();
                    $this->value($depth + 1);
                }
                $this->expect('}');

                return $none;
            case 'O':
            case 'C':
                $this->expect(':');
                if ('+' === ($this->data[$this->position] ?? '')) {
                    ++$this->position;
                }
                $class = $this->quoted();
                $this->classes[$class] = true;
                $this->expect(':');
                $count = $this->number(false);
                $this->expect(':{');
                if ('C' === $type) {
                    // Custom serialization: the class reads these bytes itself.
                    $this->bytes($count);
                    $this->expect('}');

                    return ['class' => $class, 'message' => null];
                }
                $message = null;
                for ($i = 0; $i < $count; ++$i) {
                    $property = $this->key();
                    $value = $this->value($depth + 1);
                    if (Envelope::class === $class && "\0".Envelope::class."\0message" === $property) {
                        $message = $value['class'];
                    }
                }
                $this->expect('}');

                return ['class' => $class, 'message' => $message];
            default:
                throw new \UnexpectedValueException('Not a serialized value.');
        }
    }

    /**
     * An array key or property name: an integer or a string.
     */
    private function key(): ?string
    {
        $type = $this->data[$this->position] ?? '';
        if ('i' === $type) {
            $this->value(0);

            return null;
        }
        if ('s' !== $type) {
            throw new \UnexpectedValueException('Invalid key.');
        }
        ++$this->position;
        $this->expect(':');
        $key = $this->quoted();
        $this->expect(';');

        return $key;
    }

    /**
     * The length and opening quote of a string: `<digits>:"`.
     */
    private function length(): int
    {
        $length = $this->number(false);
        $this->expect(':"');

        return $length;
    }

    private function bytes(int $length): string
    {
        if ($this->position + $length > strlen($this->data)) {
            throw new \UnexpectedValueException('Truncated.');
        }
        $bytes = substr($this->data, $this->position, $length);
        $this->position += $length;

        return $bytes;
    }

    /**
     * A quoted string with its length: `<digits>:"<bytes>"`.
     */
    private function quoted(): string
    {
        $bytes = $this->bytes($this->length());
        $this->expect('"');

        return $bytes;
    }

    /**
     * A string of the "S" type: $length characters, where "\xx" is one character.
     */
    private function escaped(int $length): void
    {
        for ($i = 0; $i < $length; ++$i) {
            $character = $this->data[$this->position] ?? throw new \UnexpectedValueException('Truncated.');
            if ('\\' === $character) {
                if (!ctype_xdigit(substr($this->data, $this->position + 1, 2)) || 2 !== strlen(substr($this->data, $this->position + 1, 2))) {
                    throw new \UnexpectedValueException('Invalid escape.');
                }
                $this->position += 3;
            } else {
                ++$this->position;
            }
        }
        $this->expect('"');
    }

    private function number(bool $signed): int
    {
        $start = $this->position;
        if ($signed && ('+' === ($this->data[$this->position] ?? '') || '-' === ($this->data[$this->position] ?? ''))) {
            ++$this->position;
        }
        while (ctype_digit($this->data[$this->position] ?? '')) {
            ++$this->position;
        }
        $digits = substr($this->data, $start, $this->position - $start);
        if ('' === $digits || strlen($digits) > 18) {
            throw new \UnexpectedValueException('Invalid number.');
        }

        return (int) $digits;
    }

    private function until(string $terminator): void
    {
        $end = strpos($this->data, $terminator, $this->position);
        if (false === $end) {
            throw new \UnexpectedValueException('Truncated.');
        }
        $this->position = $end + 1;
    }

    private function expect(string $expected): void
    {
        if (substr($this->data, $this->position, strlen($expected)) !== $expected) {
            throw new \UnexpectedValueException('Unexpected data.');
        }
        $this->position += strlen($expected);
    }
}
