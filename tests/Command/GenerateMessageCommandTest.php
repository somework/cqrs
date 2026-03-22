<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\GenerateMessageCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

use function dirname;

final class GenerateMessageCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/cqrs_bundle_'.uniqid();
        mkdir($this->projectDir.'/src', 0o777, true);
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
        self::assertIsString($messageContents);
        self::assertStringContainsString('final class ShipOrder implements Command', $messageContents);

        $handlerContents = file_get_contents($handlerPath);
        self::assertIsString($handlerContents);
        self::assertStringContainsString('#[AsCommandHandler(ShipOrder::class)]', $handlerContents);
        self::assertStringContainsString('public function __invoke(ShipOrder $message): mixed', $handlerContents);
    }

    public function test_fails_when_files_exist_without_force(): void
    {
        $kernel = $this->createKernelStub();
        $messagePath = $this->projectDir.'/src/App/Application/Command/ShipOrder.php';
        $handlerPath = $this->projectDir.'/src/App/Application/Command/ShipOrderHandler.php';
        mkdir(dirname($messagePath), 0o777, true);
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

    public function test_rejects_path_traversal_in_dir_option(): void
    {
        $kernel = $this->createKernelStub();
        $tester = new CommandTester(new GenerateMessageCommand($kernel));

        $exitCode = $tester->execute([
            'type' => 'command',
            'name' => 'App\\Command\\DoSomething',
            '--dir' => $this->projectDir.'/src/../../../tmp',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $exitCode);
        self::assertStringContainsString('project directory', $tester->getDisplay());
    }

    public function test_rejects_null_byte_in_path(): void
    {
        $kernel = $this->createKernelStub();
        $tester = new CommandTester(new GenerateMessageCommand($kernel));

        $exitCode = $tester->execute([
            'type' => 'command',
            'name' => 'App\\Command\\DoSomething',
            '--dir' => $this->projectDir."/src/\0exploit",
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $exitCode);
        self::assertStringContainsString('invalid characters', $tester->getDisplay());
    }

    public function test_throws_when_directory_not_writable(): void
    {
        if (0 === posix_getuid()) {
            self::markTestSkipped('Cannot test permission denial as root.');
        }

        $readOnlyDir = $this->projectDir.'/readonly';
        @mkdir($readOnlyDir, 0o777, true);
        chmod($readOnlyDir, 0o555);

        if (is_writable($readOnlyDir)) {
            chmod($readOnlyDir, 0o775);
            self::markTestSkipped('Filesystem does not enforce directory permissions.');
        }

        try {
            $kernel = $this->createKernelStub();
            $tester = new CommandTester(new GenerateMessageCommand($kernel));

            $exitCode = $tester->execute([
                'type' => 'command',
                'name' => 'App\\Command\\DoSomething',
                '--dir' => $readOnlyDir.'/nested',
            ]);

            self::assertSame(SymfonyCommand::FAILURE, $exitCode);
            self::assertStringContainsString('Unable to create directory', $tester->getDisplay());
        } finally {
            chmod($readOnlyDir, 0o775);
        }
    }

    public function test_generates_with_custom_dir_within_project(): void
    {
        $customDir = $this->projectDir.'/custom';
        mkdir($customDir, 0o777, true);

        $kernel = $this->createKernelStub();
        $tester = new CommandTester(new GenerateMessageCommand($kernel));

        $exitCode = $tester->execute([
            'type' => 'command',
            'name' => 'App\\Command\\DoSomething',
            '--dir' => $customDir,
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        self::assertFileExists($customDir.'/App/Command/DoSomething.php');
    }

    public function test_command_handler_has_mixed_return_type(): void
    {
        $kernel = $this->createKernelStub();
        $tester = new CommandTester(new GenerateMessageCommand($kernel));

        $exitCode = $tester->execute([
            'type' => 'command',
            'name' => 'App\\Command\\DoSomething',
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);

        $handlerContents = file_get_contents($this->projectDir.'/src/App/Command/DoSomethingHandler.php');
        self::assertIsString($handlerContents);
        self::assertStringContainsString('public function __invoke(DoSomething $message): mixed', $handlerContents);
        self::assertStringContainsString('return null;', $handlerContents);
    }

    public function test_query_handler_has_mixed_return_type(): void
    {
        $kernel = $this->createKernelStub();
        $tester = new CommandTester(new GenerateMessageCommand($kernel));

        $exitCode = $tester->execute([
            'type' => 'query',
            'name' => 'App\\Query\\FindSomething',
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);

        $handlerContents = file_get_contents($this->projectDir.'/src/App/Query/FindSomethingHandler.php');
        self::assertIsString($handlerContents);
        self::assertStringContainsString('public function __invoke(FindSomething $message): mixed', $handlerContents);
        self::assertStringContainsString('Return the query result', $handlerContents);
    }

    public function test_event_handler_has_void_return_type(): void
    {
        $kernel = $this->createKernelStub();
        $tester = new CommandTester(new GenerateMessageCommand($kernel));

        $exitCode = $tester->execute([
            'type' => 'event',
            'name' => 'App\\Event\\SomethingHappened',
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);

        $handlerContents = file_get_contents($this->projectDir.'/src/App/Event/SomethingHappenedHandler.php');
        self::assertIsString($handlerContents);
        self::assertStringContainsString('public function __invoke(SomethingHappened $message): void', $handlerContents);
        self::assertStringNotContainsString('return null', $handlerContents);
        self::assertStringContainsString('React to the event', $handlerContents);
    }

    public function test_force_flag_overwrites_existing_files(): void
    {
        $kernel = $this->createKernelStub();
        $messagePath = $this->projectDir.'/src/App/Command/DoSomething.php';
        $handlerPath = $this->projectDir.'/src/App/Command/DoSomethingHandler.php';
        mkdir(dirname($messagePath), 0o777, true);
        file_put_contents($messagePath, 'old content');
        file_put_contents($handlerPath, 'old content');

        $tester = new CommandTester(new GenerateMessageCommand($kernel));

        $exitCode = $tester->execute([
            'type' => 'command',
            'name' => 'App\\Command\\DoSomething',
            '--force' => true,
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);

        $messageContents = file_get_contents($messagePath);
        self::assertIsString($messageContents);
        self::assertStringContainsString('final class DoSomething implements Command', $messageContents);
        self::assertStringNotContainsString('old content', $messageContents);
    }

    public function test_custom_handler_class_name(): void
    {
        $kernel = $this->createKernelStub();
        $tester = new CommandTester(new GenerateMessageCommand($kernel));

        $exitCode = $tester->execute([
            'type' => 'command',
            'name' => 'App\\Command\\DoSomething',
            '--handler' => 'App\\Handler\\CustomDoSomethingHandler',
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);

        $handlerPath = $this->projectDir.'/src/App/Handler/CustomDoSomethingHandler.php';
        self::assertFileExists($handlerPath);

        $handlerContents = file_get_contents($handlerPath);
        self::assertIsString($handlerContents);
        self::assertStringContainsString('final class CustomDoSomethingHandler implements CommandHandler', $handlerContents);
        self::assertStringContainsString('public function __invoke(DoSomething $message): mixed', $handlerContents);
    }

    public function test_generates_query_message_with_correct_interface(): void
    {
        $kernel = $this->createKernelStub();
        $tester = new CommandTester(new GenerateMessageCommand($kernel));

        $exitCode = $tester->execute([
            'type' => 'query',
            'name' => 'App\\Query\\FindSomething',
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);

        $messagePath = $this->projectDir.'/src/App/Query/FindSomething.php';
        self::assertFileExists($messagePath);

        $messageContents = file_get_contents($messagePath);
        self::assertIsString($messageContents);
        self::assertStringContainsString('final class FindSomething implements Query', $messageContents);
        self::assertStringContainsString('use SomeWork\CqrsBundle\Contract\Query;', $messageContents);
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
