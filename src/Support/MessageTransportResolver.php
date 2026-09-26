<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionFunction;

use function array_key_exists;
use function get_debug_type;
use function is_array;
use function is_string;
use function iterator_to_array;
use function sprintf;

/**
 * Resolves Messenger transport names for a given message.
 *
 * @internal
 */
final class MessageTransportResolver
{
    public const DEFAULT_KEY = '__somework_cqrs_transport_default';

    /**
     * @var array<string, list<string>>
     */
    private array $cache = [];

    /**
     * Result of resolveFor() per message class, unless a closure computed it (closures are
     * evaluated on every call).
     *
     * @var array<class-string, list<string>|null>
     */
    private array $resolved = [];

    public function __construct(
        private readonly ContainerInterface $transports,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Transports configured for exactly this message class (no parent class, interface or default).
     *
     * @return list<string>|null
     */
    public function resolveExactFor(object $message): ?array
    {
        if (!$this->transports->has($message::class)) {
            return null;
        }

        return $this->normaliseTransports($message::class, $this->transports->get($message::class));
    }

    /**
     * @return list<string>|null
     */
    public function resolveFor(object $message): ?array
    {
        if (array_key_exists($message::class, $this->resolved)) {
            return $this->resolved[$message::class];
        }

        $match = MessageTypeLocator::match($this->transports, $message, [self::DEFAULT_KEY]);

        if (null !== $match) {
            $this->logger?->debug('Resolved transport for {message} via {match_type}', [
                'message' => $message::class,
                'match_type' => $match->type,
                'resolver' => self::class,
            ]);

            return $this->remember($message, $match->service, $this->normaliseTransports($match->type, $match->service));
        }

        if (!$this->transports->has(self::DEFAULT_KEY)) {
            $this->logger?->debug('No transport resolved for {message}', [
                'message' => $message::class,
                'resolver' => self::class,
            ]);

            return $this->resolved[$message::class] = null;
        }

        $this->logger?->debug('Resolved transport for {message} via fallback', [
            'message' => $message::class,
            'resolver' => self::class,
        ]);

        $default = $this->transports->get(self::DEFAULT_KEY);

        return $this->remember($message, $default, $this->normaliseTransports(self::DEFAULT_KEY, $default));
    }

    /**
     * @param list<string> $transports
     *
     * @return list<string>
     */
    private function remember(object $message, mixed $value, array $transports): array
    {
        if (!$value instanceof Closure) {
            $this->resolved[$message::class] = $transports;
        }

        return $transports;
    }

    /**
     * @return list<string>
     */
    private function normaliseTransports(string $key, mixed $value, bool $cacheable = true): array
    {
        if ($cacheable && array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if (is_string($value)) {
            $value = [$value];
        } elseif ($value instanceof Closure) {
            $reflection = new ReflectionFunction($value);
            $value = $reflection->getNumberOfParameters() > 0
                ? $value($this->transports)
                : $value();

            return $this->normaliseTransports($key, $value, false);
        } elseif ($value instanceof \Traversable) {
            $value = iterator_to_array($value, false);
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

        if ($cacheable) {
            $this->cache[$key] = $transports;
        }

        return $transports;
    }
}
