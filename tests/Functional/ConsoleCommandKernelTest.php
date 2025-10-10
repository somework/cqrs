<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use SomeWork\CqrsBundle\Tests\Fixture\Kernel\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

use function sprintf;

final class ConsoleCommandKernelTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function test_list_command_outputs_registered_handlers(): void
    {
        $tester = $this->executeCommand('somework:cqrs:list');

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());

        $display = $tester->getDisplay(true);

        self::assertStringContainsString('Type', $display);
        self::assertStringContainsString('CreateTaskCommand', $display);
        self::assertStringContainsString('GenerateReportCommand', $display);
        self::assertStringContainsString('FindTaskQuery', $display);
        self::assertStringContainsString('TaskCreatedEvent', $display);
        self::assertStringContainsString('messenger.bus.commands_async', $display);
        self::assertStringContainsString('messenger.bus.events_async', $display);
    }

    public function test_list_command_supports_filtering_by_type(): void
    {
        $tester = $this->executeCommand('somework:cqrs:list', ['--type' => ['query']]);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());

        $display = $tester->getDisplay(true);

        self::assertStringContainsString('FindTaskQuery', $display);
        self::assertStringNotContainsString('CreateTaskCommand', $display);
        self::assertStringNotContainsString('TaskCreatedEvent', $display);
    }

    public function test_list_command_reports_handler_configuration_details(): void
    {
        $tester = $this->executeCommand('somework:cqrs:list', ['--details' => true]);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());

        $display = $tester->getDisplay(true);

        self::assertStringContainsString('Dispatch Mode', $display);
        self::assertStringContainsString('Async Defers', $display);
        self::assertStringContainsString('SomeWork\\CqrsBundle\\Support\\NullRetryPolicy', $display);
        self::assertStringContainsString('SomeWork\\CqrsBundle\\Support\\NullMessageSerializer', $display);
        self::assertStringContainsString('SomeWork\\CqrsBundle\\Support\\RandomCorrelationMetadataProvider', $display);

        $this->assertTableContainsRows($display, 'SomeWork\\CqrsBundle\\Tests\\Fixture\\Handler\\CreateTaskHandler', [
            ['Message', 'CreateTaskCommand'],
            ['Dispatch Mode', 'sync'],
            ['Async Defers', 'yes'],
        ]);

        $this->assertTableContainsRows($display, 'SomeWork\\CqrsBundle\\Tests\\Fixture\\Handler\\FindTaskHandler', [
            ['Message', 'FindTaskQuery'],
            ['Dispatch Mode', 'sync'],
            ['Async Defers', 'n/a'],
        ]);
    }

    private function executeCommand(string $name, array $input = []): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $command = $application->find($name);
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    /**
     * @param list<array{0: string, 1: string}> $expectedRows
     */
    private function assertTableContainsRows(string $output, string $needle, array $expectedRows): void
    {
        $table = $this->findTableFor($output, $needle);

        self::assertNotNull($table, sprintf('Failed to locate table containing "%s".', $needle));

        foreach ($expectedRows as [$field, $value]) {
            $pattern = sprintf('/║\s*%s\s*│\s*%s\s*║/', preg_quote($field, '/'), preg_quote((string) $value, '/'));
            self::assertMatchesRegularExpression($pattern, $table);
        }
    }

    private function findTableFor(string $output, string $needle): ?string
    {
        if (!preg_match_all('/╔.*?╚.*?╝/s', $output, $matches)) {
            return null;
        }

        foreach ($matches[0] as $table) {
            if (str_contains($table, $needle)) {
                return $table;
            }
        }

        return null;
    }
}
