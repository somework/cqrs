<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing;

use PHPUnit\Framework\TestCase;

/**
 * Abstract test case that includes the CQRS assertion helpers (CqrsAssertionsTrait).
 *
 * Only the bundle's internal message-type cache is reset before each test: create the fake
 * buses in each test (or setUp()), they are not reset for you.
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
