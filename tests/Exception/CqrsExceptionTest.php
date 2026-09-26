<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Exception\CqrsException;

use function basename;
use function glob;
use function is_subclass_of;

#[CoversNothing]
final class CqrsExceptionTest extends TestCase
{
    public function test_every_exception_of_the_bundle_implements_the_marker(): void
    {
        $files = [...(array) glob(__DIR__.'/../../src/Exception/*Exception.php'), __DIR__.'/../../src/Outbox/SetupLockLeftBehind.php'];
        $checked = 0;

        foreach ($files as $file) {
            $class = (str_contains((string) $file, '/Outbox/') ? 'SomeWork\\CqrsBundle\\Outbox\\' : 'SomeWork\\CqrsBundle\\Exception\\').basename((string) $file, '.php');
            if (CqrsException::class === $class) {
                continue;
            }

            self::assertTrue(is_subclass_of($class, CqrsException::class), $class);
            ++$checked;
        }

        self::assertGreaterThanOrEqual(7, $checked);
    }
}
