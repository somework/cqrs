<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Attribute;

use Attribute;

/**
 * Marks a message class for automatic async transport routing.
 *
 * When present on a message class, the AsynchronousStampDecider adds a
 * TransportNamesStamp with the configured transport name, eliminating the
 * need for YAML transport mapping configuration.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Asynchronous
{
    /**
     * @param non-empty-string|null $transport Transport name (defaults to 'async' when null)
     */
    public function __construct(
        public readonly ?string $transport = null,
    ) {
    }
}
