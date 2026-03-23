<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Contract for dispatching query messages.
 *
 * Type-hint this interface in application code to decouple from the concrete
 * bus implementation and enable easy test-double substitution.
 *
 * @api
 */
interface QueryBusInterface
{
    public function ask(Query $query, StampInterface ...$stamps): mixed;
}
