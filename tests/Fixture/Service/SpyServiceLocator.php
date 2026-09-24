<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Service;

use Symfony\Component\DependencyInjection\ServiceLocator;

use function count;

/**
 * Service locator recording every lookup.
 *
 * @extends ServiceLocator<object>
 */
final class SpyServiceLocator extends ServiceLocator
{
    /** @var list<string> */
    public array $hasCalls = [];

    /** @var list<string> */
    public array $getCalls = [];

    public function has(string $id): bool
    {
        $this->hasCalls[] = $id;

        return parent::has($id);
    }

    public function get(string $id): mixed
    {
        $this->getCalls[] = $id;

        return parent::get($id);
    }

    public function lookupCount(): int
    {
        return count($this->hasCalls);
    }
}
