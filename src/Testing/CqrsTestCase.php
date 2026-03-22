<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing;

use PHPUnit\Framework\TestCase;

/**
 * Abstract test case that includes CQRS assertion helpers and automatic state reset.
 *
 * Extend this class for simple unit tests. If you already extend KernelTestCase
 * or WebTestCase, use CqrsAssertionsTrait directly instead.
 *
 * @api
 */
abstract class CqrsTestCase extends TestCase
{
    use CqrsAssertionsTrait;
}
