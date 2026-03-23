<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use ReflectionClass;
use SomeWork\CqrsBundle\Attribute\Asynchronous;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Reads #[Asynchronous] attribute from message classes and adds TransportNamesStamp.
 *
 * @internal
 */
final class AsynchronousStampDecider implements StampDecider
{
    private const DEFAULT_TRANSPORT = 'async';

    /**
     * @var array<class-string, array{present: bool, transport: string}>
     */
    private array $attributeCache = [];

    /**
     * @param array<int, StampInterface> $stamps
     *
     * @return array<int, StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        if (DispatchMode::SYNC === $mode) {
            return $stamps;
        }

        $cached = $this->resolveAttribute($message);

        if (!$cached['present']) {
            return $stamps;
        }

        foreach ($stamps as $stamp) {
            if ($stamp instanceof TransportNamesStamp) {
                return $stamps;
            }
        }

        $stamps[] = new TransportNamesStamp([$cached['transport']]);

        return $stamps;
    }

    /**
     * @return array{present: bool, transport: string}
     */
    private function resolveAttribute(object $message): array
    {
        $class = $message::class;

        if (isset($this->attributeCache[$class])) {
            return $this->attributeCache[$class];
        }

        $reflection = new ReflectionClass($message);
        $attributes = $reflection->getAttributes(Asynchronous::class);

        if ([] === $attributes) {
            return $this->attributeCache[$class] = ['present' => false, 'transport' => self::DEFAULT_TRANSPORT];
        }

        $instance = $attributes[0]->newInstance();

        return $this->attributeCache[$class] = [
            'present' => true,
            'transport' => $instance->transport ?? self::DEFAULT_TRANSPORT,
        ];
    }
}
