<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Container\ContainerInterface;

use function get_debug_type;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Resolves Messenger transport names for a given message.
 */
final class MessageTransportResolver
{
    public const DEFAULT_KEY = '__somework_cqrs_transport_default';

    public function __construct(
        private readonly ContainerInterface $transports,
    ) {
    }

    /**
     * @return list<string>|null
     */
    public function resolveFor(object $message): ?array
    {
        $match = MessageTypeLocator::match($this->transports, $message, [self::DEFAULT_KEY]);

        if (null !== $match) {
            return $this->normaliseTransports($match->type, $match->service);
        }

        if (!$this->transports->has(self::DEFAULT_KEY)) {
            return null;
        }

        return $this->normaliseTransports(self::DEFAULT_KEY, $this->transports->get(self::DEFAULT_KEY));
    }

    /**
     * @return list<string>
     */
    private function normaliseTransports(string $key, mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        } elseif (!is_array($value)) {
            throw new \LogicException(sprintf('Transport override for "%s" must be a string or list of strings, got %s.', $key, get_debug_type($value)));
        }

        $transports = [];
        $seen = [];

        foreach ($value as $transport) {
            if (!is_string($transport)) {
                throw new \LogicException(sprintf('Transport override for "%s" must be a string or list of strings, got element of type %s.', $key, get_debug_type($transport)));
            }

            if (isset($seen[$transport])) {
                continue;
            }

            $seen[$transport] = true;
            $transports[] = $transport;
        }

        return $transports;
    }
}
