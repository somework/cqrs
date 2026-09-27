<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use function get_object_vars;

/**
 * Compares messages and stamps by class and public properties: assertSame() compares objects by
 * identity, and the code style turns assertEquals() into assertSame().
 */
trait AssertsMessages
{
    /**
     * @param list<object>          $expected
     * @param iterable<object|null> $actual
     */
    private static function assertSameMessages(array $expected, iterable $actual, string $message = ''): void
    {
        self::assertSame(self::describeMessages($expected), self::describeMessages($actual), $message);
    }

    /**
     * @param iterable<object|null> $messages
     *
     * @return list<array{class-string, array<string, mixed>}|null>
     */
    private static function describeMessages(iterable $messages): array
    {
        $described = [];
        foreach ($messages as $message) {
            $described[] = null === $message ? null : [$message::class, get_object_vars($message)];
        }

        return $described;
    }
}
