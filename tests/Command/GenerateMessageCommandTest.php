<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\GenerateMessageCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class GenerateMessageCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/cqrs_bundle_'.uniqid();
        mkdir($this->projectDir.'/src', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function test_generates_message_and_handler_files(): void
    {
        $kernel = $this->createKernelStub();
        $tester = new CommandTester(new GenerateMessageCommand($kernel));

        $exitCode = $tester->execute([
            'type' => 'command',
            'name' => 'App\\Application\\Command\\ShipOrder',
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        self::assertStringContainsString('Generated src/App/Application/Command/ShipOrder.php', $tester->getDisplay());

        $messagePath = $this->projectDir.'/src/App/Application/Command/ShipOrder.php';
        $handlerPath = $this->projectDir.'/src/App/Application/Command/ShipOrderHandler.php';

        self::assertFileExists($messagePath);
        self::assertFileExists($handlerPath);

        $messageContents = file_get_contents($messagePath);
        self::assertStringContainsString('final class ShipOrder implements Command', $messageContents ?: '');

        $handlerContents = file_get_contents($handlerPath);
        self::assertStringContainsString('#[AsCommandHandler(ShipOrder::class)]', $handlerContents ?: '');
        self::assertStringContainsString('public function __invoke(ShipOrder $message): mixed', $handlerContents ?: '');
    }

    public function test_fails_when_files_exist_without_force(): void
    {
        $kernel = $this->createKernelStub();
        $messagePath = $this->projectDir.'/src/App/Application/Command/ShipOrder.php';
        $handlerPath = $this->projectDir.'/src/App/Application/Command/ShipOrderHandler.php';
        mkdir(dirname($messagePath), 0777, true);
        file_put_contents($messagePath, 'existing');
        file_put_contents($handlerPath, 'existing');

        $tester = new CommandTester(new GenerateMessageCommand($kernel));

        $exitCode = $tester->execute([
            'type' => 'command',
            'name' => 'App\\Application\\Command\\ShipOrder',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $exitCode);
        self::assertStringContainsString('already exists', $tester->getDisplay());
        self::assertSame('existing', file_get_contents($messagePath));
    }

    public function test_invalid_type_displays_error(): void
    {
        $kernel = $this->createKernelStub();
        $tester = new CommandTester(new GenerateMessageCommand($kernel));

        $exitCode = $tester->execute([
            'type' => 'unknown',
            'name' => 'App\\Message',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $exitCode);
        self::assertStringContainsString('Supported types are: command, query, event.', $tester->getDisplay());
    }

    private function createKernelStub(): KernelInterface
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->projectDir);

        return $kernel;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($directory);
    }
}

