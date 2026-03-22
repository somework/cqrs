<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\HealthCheckCommand;
use SomeWork\CqrsBundle\Health\CheckResult;
use SomeWork\CqrsBundle\Health\CheckSeverity;
use SomeWork\CqrsBundle\Health\HealthChecker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(HealthCheckCommand::class)]
final class HealthCheckCommandTest extends TestCase
{
    public function test_returns_success_when_all_checks_pass(): void
    {
        $checker = $this->createChecker([
            new CheckResult(CheckSeverity::OK, 'handler', 'Handler "app.handler" is resolvable'),
        ]);

        $tester = $this->executeTester([$checker]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('All checks passed', $tester->getDisplay());
    }

    public function test_returns_failure_when_warning_present(): void
    {
        $checker = $this->createChecker([
            new CheckResult(CheckSeverity::WARNING, 'handler', 'No handlers registered'),
        ]);

        $tester = $this->executeTester([$checker]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function test_returns_invalid_when_critical_present(): void
    {
        $checker = $this->createChecker([
            new CheckResult(CheckSeverity::CRITICAL, 'handler', 'Handler "missing" is not resolvable'),
        ]);

        $tester = $this->executeTester([$checker]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    public function test_worst_severity_wins(): void
    {
        $checker = $this->createChecker([
            new CheckResult(CheckSeverity::OK, 'handler', 'ok'),
            new CheckResult(CheckSeverity::WARNING, 'handler', 'warning'),
            new CheckResult(CheckSeverity::CRITICAL, 'transport', 'critical'),
        ]);

        $tester = $this->executeTester([$checker]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    public function test_displays_results_table(): void
    {
        $checker = $this->createChecker([
            new CheckResult(CheckSeverity::OK, 'handler', 'Handler "app.handler" is resolvable'),
            new CheckResult(CheckSeverity::CRITICAL, 'transport', 'Transport "missing" is not valid'),
        ]);

        $tester = $this->executeTester([$checker]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Handler "app.handler" is resolvable', $display);
        self::assertStringContainsString('Transport "missing" is not valid', $display);
        self::assertStringContainsString('OK', $display);
        self::assertStringContainsString('CRITICAL', $display);
    }

    public function test_handles_no_checkers(): void
    {
        $tester = $this->executeTester([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('All checks passed', $tester->getDisplay());
    }

    public function test_command_name_is_correct(): void
    {
        $command = new HealthCheckCommand([]);

        self::assertSame('somework:cqrs:health', $command->getName());
    }

    public function test_command_description_is_set(): void
    {
        $command = new HealthCheckCommand([]);

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertNotNull($command->getDescription());
        self::assertNotEmpty($command->getDescription());
    }

    public function test_multiple_checkers_results_aggregated(): void
    {
        $checkerA = $this->createChecker([
            new CheckResult(CheckSeverity::OK, 'handler', 'Handler OK'),
        ]);
        $checkerB = $this->createChecker([
            new CheckResult(CheckSeverity::OK, 'transport', 'Transport OK'),
        ]);

        $tester = $this->executeTester([$checkerA, $checkerB]);

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Handler OK', $display);
        self::assertStringContainsString('Transport OK', $display);
    }

    public function test_warning_message_displayed_for_warning_severity(): void
    {
        $checker = $this->createChecker([
            new CheckResult(CheckSeverity::WARNING, 'handler', 'No handlers registered'),
        ]);

        $tester = $this->executeTester([$checker]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('warnings', $display);
        self::assertStringContainsString('WARNING', $display);
    }

    public function test_error_message_displayed_for_critical_severity(): void
    {
        $checker = $this->createChecker([
            new CheckResult(CheckSeverity::CRITICAL, 'transport', 'Transport "bad" is not valid'),
        ]);

        $tester = $this->executeTester([$checker]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('critical issues', $display);
        self::assertStringContainsString('CRITICAL', $display);
    }

    /**
     * @param list<CheckResult> $results
     */
    private function createChecker(array $results): HealthChecker
    {
        return new class($results) implements HealthChecker {
            /** @param list<CheckResult> $results */
            public function __construct(private readonly array $results)
            {
            }

            public function check(): array
            {
                return $this->results;
            }
        };
    }

    /**
     * @param list<HealthChecker> $checkers
     */
    private function executeTester(array $checkers): CommandTester
    {
        $command = new HealthCheckCommand($checkers);
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }
}
