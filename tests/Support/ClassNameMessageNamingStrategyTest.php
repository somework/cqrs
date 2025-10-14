<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Support\ClassNameMessageNamingStrategy;

use function sprintf;

final class ClassNameMessageNamingStrategyTest extends TestCase
{
    private ClassNameMessageNamingStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new ClassNameMessageNamingStrategy();
    }

    /**
     * @dataProvider provideMessageClassLabels
     */
    public function test_it_returns_documented_label(string $messageClass, string $expectedLabel): void
    {
        self::assertSame(
            $expectedLabel,
            $this->strategy->getName($messageClass),
            sprintf('The label for "%s" documents the default handler presentation.', $messageClass),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideMessageClassLabels(): iterable
    {
        yield 'namespaced classes use their short name' => ['App\\Domain\\UserRegistered', 'UserRegistered'];
        yield 'global namespace classes keep their class name' => ['RegisterUser', 'RegisterUser'];
        yield 'trailing namespace separators fall back to the original string' => ['App\\Domain\\', 'App\\Domain\\'];
    }
}
