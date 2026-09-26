<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

/**
 * Stands for a class with side effects on unserialize() (a gadget) in a forged outbox row.
 */
final class UnserializeGadget
{
    public static bool $woken = false;

    public string $command = 'id';

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        self::$woken = true;
    }
}
