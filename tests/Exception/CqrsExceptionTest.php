<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Exception\CqrsException;
use SomeWork\CqrsBundle\Outbox\Relay\OutboxRelay;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;

use function basename;
use function class_exists;
use function count;
use function file_get_contents;
use function glob;
use function implode;
use function in_array;
use function is_array;
use function is_subclass_of;
use function ltrim;
use function preg_match;
use function preg_match_all;
use function realpath;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function token_get_all;

use const PREG_SET_ORDER;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NEW;
use const T_STRING;
use const T_THROW;
use const T_WHITESPACE;

#[CoversNothing]
final class CqrsExceptionTest extends TestCase
{
    /**
     * Errors of the container build (the configuration, compiler passes), which no application code
     * catches: they are Symfony's configuration exceptions (or \LogicException and friends).
     */
    private const BUILD_TIME = '/src/DependencyInjection/';

    /**
     * Exceptions of other libraries the bundle throws on purpose: [class of the file, thrown class, reason].
     */
    private const ALLOWED = [
        [OutboxRelay::class, MessageDecodingFailedException::class, 'Messenger\'s own error for a row it cannot decode, handled by the relay itself'],
    ];

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

        self::assertGreaterThanOrEqual(11, $checked);
    }

    public function test_the_bundle_throws_only_exceptions_that_implement_the_marker(): void
    {
        $sources = realpath(__DIR__.'/../../src');
        self::assertIsString($sources);

        $violations = [];
        $throws = 0;
        foreach (self::phpFiles($sources) as $file) {
            if (str_contains($file, self::BUILD_TIME)) {
                continue;
            }

            foreach (self::thrownClasses((string) file_get_contents($file)) as [$class, $line, $fileClass]) {
                ++$throws;
                if (self::isAllowed($fileClass, $class)) {
                    continue;
                }
                if (!class_exists($class) || !is_subclass_of($class, CqrsException::class)) {
                    $violations[] = sprintf('%s:%d throws %s', substr($file, strlen($sources) + 1), $line, $class);
                }
            }
        }

        self::assertSame([], $violations, "Throw an exception of the bundle (e.g. SomeWork\\CqrsBundle\\Exception\\LogicException, which extends \\LogicException) so that catch (CqrsException) catches it:\n".implode("\n", $violations));
        // The scan found the throw sites (e.g. the tokens of PHP changed).
        self::assertGreaterThan(80, $throws);
    }

    public function test_the_scan_finds_the_classes_of_throw_expressions(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App\Service;

            use Foo\BarException;
            use Foo\Other as Aliased;

            final class Example
            {
                public function run(?string $value): string
                {
                    $text = 'throw new \NotThrown()';
                    $value ?? throw new BarException();
                    if ('' === $value) {
                        throw new Aliased();
                    }
                    if ('local' === $value) {
                        throw new Local\Problem();
                    }
                    try {
                        throw new \LogicException($text);
                    } catch (\LogicException $exception) {
                        throw $exception;
                    }
                }
            }
            PHP;

        self::assertSame(
            [['Foo\BarException', 12, 'App\Service\Example'], ['Foo\Other', 14, 'App\Service\Example'], ['App\Service\Local\Problem', 17, 'App\Service\Example'], ['LogicException', 20, 'App\Service\Example']],
            self::thrownClasses($code),
        );
    }

    private static function isAllowed(?string $fileClass, string $class): bool
    {
        foreach (self::ALLOWED as [$allowedIn, $allowed]) {
            if ($allowedIn === $fileClass && $allowed === $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * The classes of the "throw new <class>" expressions of a file, resolved with its imports.
     *
     * @return list<array{string, int, string|null}> [thrown class, line, class of the file]
     */
    private static function thrownClasses(string $code): array
    {
        $namespace = 1 === preg_match('/^namespace\s+([^;]+);/m', $code, $match) ? $match[1] : '';
        $fileClass = 1 === preg_match('/^(?:final\s+|abstract\s+)?(?:class|trait|interface|enum)\s+(\w+)/m', $code, $match) ? ltrim($namespace.'\\'.$match[1], '\\') : null;

        /** @var array<string, string> $imports lowercase alias => class */
        $imports = [];
        preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $code, $matches, PREG_SET_ORDER);
        foreach ($matches as $use) {
            $alias = $use[2] ?? '';
            $imports[strtolower('' !== $alias ? $alias : substr($use[1], (int) strrpos('\\'.$use[1], '\\')))] = $use[1];
        }

        $tokens = token_get_all($code);
        $thrown = [];
        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            if (!is_array($tokens[$i]) || T_THROW !== $tokens[$i][0]) {
                continue;
            }

            $next = self::nextToken($tokens, $i);
            if (null === $next || T_NEW !== $tokens[$next][0]) {
                continue; // a rethrow, or a factory method
            }
            $name = self::nextToken($tokens, $next);
            if (null === $name || !is_array($tokens[$name])) {
                continue;
            }

            [$type, $text, $line] = $tokens[$name];
            $class = match (true) {
                T_NAME_FULLY_QUALIFIED === $type => ltrim($text, '\\'),
                in_array(strtolower($text), ['self', 'static'], true) => (string) $fileClass,
                T_STRING === $type && isset($imports[strtolower($text)]) => $imports[strtolower($text)],
                T_NAME_QUALIFIED === $type && isset($imports[strtolower(substr($text, 0, (int) strpos($text, '\\')))]) => $imports[strtolower(substr($text, 0, (int) strpos($text, '\\')))].substr($text, (int) strpos($text, '\\')),
                default => ltrim($namespace.'\\'.$text, '\\'),
            };
            $thrown[] = [$class, $line, $fileClass];
        }

        return $thrown;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function nextToken(array $tokens, int $index): ?int
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; ++$i) {
            if (!is_array($tokens[$i]) || T_WHITESPACE !== $tokens[$i][0]) {
                return is_array($tokens[$i]) ? $i : null;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && str_starts_with($file->getExtension(), 'php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
