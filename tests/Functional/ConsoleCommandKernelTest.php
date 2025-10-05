<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use SomeWork\CqrsBundle\Tests\Fixture\Kernel\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

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
        self::assertMatchesRegularExpression('/CreateTaskCommand[^\n]+sync[^\n]+yes/', $display);
        self::assertMatchesRegularExpression('/FindTaskQuery[^\n]+sync[^\n]+n\/a/', $display);
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
}
