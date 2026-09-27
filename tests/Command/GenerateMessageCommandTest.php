<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\GenerateMessageCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

use function dirname;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function json_encode;
use function mkdir;
use function preg_replace;
use function sys_get_temp_dir;
use function uniqid;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

#[CoversClass(GenerateMessageCommand::class)]
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
        (new Filesystem())->remove($this->projectDir);
    }

    public function test_follows_the_psr4_mapping_of_the_project(): void
    {
        $this->writeComposerJson(['App\\' => 'src/']);

        $tester = $this->execute(['type' => 'command', 'name' => 'App\\Application\\Command\\ShipOrder']);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Generated src/Application/Command/ShipOrder.php and src/Application/Command/ShipOrderHandler.php.', self::display($tester));
        self::assertStringNotContainsString('not covered by a PSR-4 prefix', self::display($tester));

        $message = $this->read('src/Application/Command/ShipOrder.php');
        self::assertStringContainsString('namespace App\\Application\\Command;', $message);
        self::assertStringContainsString('final class ShipOrder implements Command', $message);
        self::assertStringContainsString('public readonly string $id,', $message);

        $handler = $this->read('src/Application/Command/ShipOrderHandler.php');
        self::assertStringContainsString('#[AsCommandHandler(ShipOrder::class)]', $handler);
        self::assertStringContainsString('final class ShipOrderHandler', $handler);
        self::assertStringContainsString('public function __invoke(ShipOrder $command): mixed', $handler);
        // Same namespace: the message needs no import.
        self::assertStringNotContainsString('use App\\Application\\Command\\ShipOrder;', $handler);
    }

    public function test_uses_the_longest_matching_prefix_including_autoload_dev(): void
    {
        $this->writeComposerJson(['App\\' => 'src/', 'App\\Billing\\' => ['modules/billing/src/', 'other/']], ['Tests\\' => 'tests/']);

        self::assertSame(SymfonyCommand::SUCCESS, $this->execute(['type' => 'event', 'name' => 'App\\Billing\\InvoicePaid'])->getStatusCode());
        self::assertFileExists($this->projectDir.'/modules/billing/src/InvoicePaid.php');

        self::assertSame(SymfonyCommand::SUCCESS, $this->execute(['type' => 'query', 'name' => 'Tests\\Fixture\\FindThing'])->getStatusCode());
        self::assertFileExists($this->projectDir.'/tests/Fixture/FindThingHandler.php');
    }

    public function test_dir_option_replaces_the_mapped_directory(): void
    {
        $this->writeComposerJson(['App\\' => 'src/']);

        $tester = $this->execute(['type' => 'command', 'name' => 'App\\Command\\DoSomething', '--dir' => 'lib']);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($this->projectDir.'/lib/Command/DoSomething.php');
        self::assertFileExists($this->projectDir.'/lib/Command/DoSomethingHandler.php');
    }

    public function test_without_a_psr4_mapping_the_full_class_path_is_used_and_a_warning_shown(): void
    {
        $tester = $this->execute(['type' => 'command', 'name' => '\\App\\Command\\DoSomething']);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->projectDir.'/src/App/Command/DoSomething.php');
        self::assertStringContainsString('not covered by a PSR-4 prefix', self::display($tester));
    }

    public function test_generated_code_compiles_and_is_registered_by_attribute(): void
    {
        $this->writeComposerJson(['App\\' => 'src/']);

        foreach (['command' => 'App\\Command\\ShipOrder', 'query' => 'App\\Query\\FindOrder', 'event' => 'App\\Event\\OrderShipped'] as $type => $class) {
            self::assertSame(SymfonyCommand::SUCCESS, $this->execute(['type' => $type, 'name' => $class])->getStatusCode());
        }
        self::assertSame(SymfonyCommand::SUCCESS, $this->execute(['type' => 'command', 'name' => 'App\\Command\\CancelOrder', '--handler' => 'App\\Handler\\CancelOrder'])->getStatusCode());

        $script = <<<'PHP'
            require $argv[1];
            spl_autoload_register(static function (string $class) use ($argv): void {
                $file = $argv[2].'/src/'.str_replace(['App\\', '\\'], ['', '/'], $class).'.php';
                if (is_file($file)) {
                    require $file;
                }
            });
            $checks = [
                ['App\Command\ShipOrderHandler', 'App\Command\ShipOrder', SomeWork\CqrsBundle\Attribute\AsCommandHandler::class, 'command'],
                ['App\Query\FindOrderHandler', 'App\Query\FindOrder', SomeWork\CqrsBundle\Attribute\AsQueryHandler::class, 'query'],
                ['App\Event\OrderShippedHandler', 'App\Event\OrderShipped', SomeWork\CqrsBundle\Attribute\AsEventHandler::class, 'event'],
                ['App\Handler\CancelOrder', 'App\Command\CancelOrder', SomeWork\CqrsBundle\Attribute\AsCommandHandler::class, 'command'],
            ];
            foreach ($checks as [$handlerClass, $messageClass, $attributeClass, $property]) {
                $attribute = (new ReflectionClass($handlerClass))->getAttributes($attributeClass)[0]->newInstance();
                if ($attribute->{$property} !== $messageClass) {
                    throw new LogicException($handlerClass.' is registered for '.$attribute->{$property});
                }
                try {
                    (new $handlerClass())(new $messageClass('42'));
                } catch (LogicException $exception) {
                    if ('query' !== $property) {
                        throw $exception;
                    }
                }
            }
            echo 'OK';
            PHP;

        $command = implode(' ', [PHP_BINARY, '-r', escapeshellarg($script), escapeshellarg(dirname(__DIR__, 2).'/vendor/autoload.php'), escapeshellarg($this->projectDir)]);
        exec($command.' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertSame(['OK'], $output);
    }

    public function test_names_that_clash_with_imports_still_compile(): void
    {
        $this->writeComposerJson(['App\\' => 'src/']);

        self::assertSame(SymfonyCommand::SUCCESS, $this->execute(['type' => 'command', 'name' => 'App\\Messaging\\Command', '--handler' => 'App\\Handler\\AsCommandHandler'])->getStatusCode());
        self::assertSame(SymfonyCommand::SUCCESS, $this->execute(['type' => 'event', 'name' => 'App\\Messaging\\Event'])->getStatusCode());

        self::assertStringContainsString('use SomeWork\\CqrsBundle\\Contract\\Command as CommandContract;', $this->read('src/Messaging/Command.php'));
        self::assertStringContainsString('#[AsCommandHandlerAttribute(Command::class)]', $this->read('src/Handler/AsCommandHandler.php'));

        $script = <<<'PHP'
            require $argv[1];
            foreach (['src/Messaging/Command.php', 'src/Messaging/Event.php', 'src/Handler/AsCommandHandler.php', 'src/Messaging/EventHandler.php'] as $file) {
                require $argv[2].'/'.$file;
            }
            $attribute = (new ReflectionClass('App\Handler\AsCommandHandler'))->getAttributes()[0]->newInstance();
            echo $attribute->command;
            PHP;

        $command = implode(' ', [PHP_BINARY, '-r', escapeshellarg($script), escapeshellarg(dirname(__DIR__, 2).'/vendor/autoload.php'), escapeshellarg($this->projectDir)]);
        exec($command.' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertSame(['App\\Messaging\\Command'], $output);
    }

    public function test_refuses_to_write_through_a_symlinked_file(): void
    {
        $this->writeComposerJson(['App\\' => 'src/']);
        $outside = sys_get_temp_dir().'/cqrs_bundle_victim_'.uniqid().'.php';
        file_put_contents($outside, 'original');
        mkdir($this->projectDir.'/src/Command', 0o777, true);
        (new Filesystem())->symlink($outside, $this->projectDir.'/src/Command/DoSomething.php');

        try {
            $tester = $this->execute(['type' => 'command', 'name' => 'App\\Command\\DoSomething', '--force' => true]);

            self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('is a symbolic link', self::display($tester));
            self::assertSame('original', file_get_contents($outside));
        } finally {
            (new Filesystem())->remove($outside);
        }
    }

    public function test_aliases_the_message_when_its_short_name_clashes_with_the_handler(): void
    {
        $this->writeComposerJson(['App\\' => 'src/']);

        $this->execute(['type' => 'command', 'name' => 'App\\Command\\CancelOrder', '--handler' => 'App\\Handler\\CancelOrder']);

        $handler = $this->read('src/Handler/CancelOrder.php');
        self::assertStringContainsString('use App\\Command\\CancelOrder as CancelOrderMessage;', $handler);
        self::assertStringContainsString('#[AsCommandHandler(CancelOrderMessage::class)]', $handler);
        self::assertStringContainsString('public function __invoke(CancelOrderMessage $command): mixed', $handler);
    }

    public function test_query_and_event_handler_signatures(): void
    {
        $this->writeComposerJson(['App\\' => 'src/']);

        $this->execute(['type' => 'query', 'name' => 'App\\Query\\FindSomething', '--handler' => 'App\\Handler\\FindSomethingHandler']);
        $this->execute(['type' => 'event', 'name' => 'App\\Event\\SomethingHappened']);

        $queryHandler = $this->read('src/Handler/FindSomethingHandler.php');
        self::assertStringContainsString('use App\\Query\\FindSomething;', $queryHandler);
        self::assertStringContainsString('#[AsQueryHandler(FindSomething::class)]', $queryHandler);
        self::assertStringContainsString('public function __invoke(FindSomething $query): mixed', $queryHandler);
        self::assertStringContainsString('final class FindSomething implements Query', $this->read('src/Query/FindSomething.php'));

        $eventHandler = $this->read('src/Event/SomethingHappenedHandler.php');
        self::assertStringContainsString('#[AsEventHandler(SomethingHappened::class)]', $eventHandler);
        self::assertStringContainsString('public function __invoke(SomethingHappened $event): void', $eventHandler);
        self::assertStringContainsString('final class SomethingHappened implements Event', $this->read('src/Event/SomethingHappened.php'));
    }

    public function test_writes_nothing_when_one_of_the_files_exists(): void
    {
        $this->writeComposerJson(['App\\' => 'src/']);
        mkdir($this->projectDir.'/src/Command', 0o777, true);
        file_put_contents($this->projectDir.'/src/Command/DoSomethingHandler.php', 'existing');

        $tester = $this->execute(['type' => 'command', 'name' => 'App\\Command\\DoSomething']);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('File "src/Command/DoSomethingHandler.php" already exists. Use --force to overwrite.', self::display($tester));
        self::assertFileDoesNotExist($this->projectDir.'/src/Command/DoSomething.php');
        self::assertSame('existing', $this->read('src/Command/DoSomethingHandler.php'));
    }

    public function test_force_overwrites_existing_files(): void
    {
        $this->writeComposerJson(['App\\' => 'src/']);
        mkdir($this->projectDir.'/src/Command', 0o777, true);
        file_put_contents($this->projectDir.'/src/Command/DoSomething.php', 'old content');

        $tester = $this->execute(['type' => 'command', 'name' => 'App\\Command\\DoSomething', '--force' => true]);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('final class DoSomething implements Command', $this->read('src/Command/DoSomething.php'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidClassNames(): iterable
    {
        yield 'no namespace' => ['DoSomething', 'not a valid fully-qualified class name'];
        yield 'parent segment' => ['App\\..\\..\\Evil', 'not a valid fully-qualified class name'];
        yield 'slashes' => ['App/Command/DoSomething', 'not a valid fully-qualified class name'];
        yield 'trailing separator' => ['App\\Command\\', 'not a valid fully-qualified class name'];
        yield 'reserved word' => ['App\\Command\\List', '"List" is a reserved word'];
    }

    #[DataProvider('invalidClassNames')]
    public function test_rejects_invalid_class_names(string $class, string $error): void
    {
        $tester = $this->execute(['type' => 'command', 'name' => $class]);

        self::assertSame(SymfonyCommand::INVALID, $tester->getStatusCode());
        self::assertStringContainsString($error, self::display($tester));
        self::assertSame([], (array) glob($this->projectDir.'/src/*'));
    }

    public function test_rejects_an_invalid_handler_class_or_the_message_class_as_handler(): void
    {
        $tester = $this->execute(['type' => 'command', 'name' => 'App\\Command\\DoSomething', '--handler' => 'Handler']);
        self::assertSame(SymfonyCommand::INVALID, $tester->getStatusCode());

        $tester = $this->execute(['type' => 'command', 'name' => 'App\\Command\\DoSomething', '--handler' => '\\App\\Command\\DoSomething']);
        self::assertSame(SymfonyCommand::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('The handler class must differ from the message class.', self::display($tester));
    }

    public function test_rejects_a_dir_outside_of_the_project(): void
    {
        foreach (['../outside', $this->projectDir.'/src/../../outside', '/tmp'] as $dir) {
            $tester = $this->execute(['type' => 'command', 'name' => 'App\\Command\\DoSomething', '--dir' => $dir]);

            self::assertSame(SymfonyCommand::INVALID, $tester->getStatusCode(), $dir);
            self::assertStringContainsString('must be within the project directory', self::display($tester));
        }
    }

    public function test_rejects_a_sibling_directory_sharing_the_project_prefix(): void
    {
        $sibling = $this->projectDir.'-sibling';
        mkdir($sibling);

        try {
            $tester = $this->execute(['type' => 'command', 'name' => 'App\\Command\\DoSomething', '--dir' => $sibling]);

            self::assertSame(SymfonyCommand::INVALID, $tester->getStatusCode());
            self::assertSame([], (array) glob($sibling.'/*'));
        } finally {
            (new Filesystem())->remove($sibling);
        }
    }

    public function test_rejects_a_symlink_pointing_outside_of_the_project(): void
    {
        $outside = sys_get_temp_dir().'/cqrs_bundle_outside_'.uniqid();
        mkdir($outside);
        (new Filesystem())->symlink($outside, $this->projectDir.'/link');

        try {
            $tester = $this->execute(['type' => 'command', 'name' => 'App\\Command\\DoSomething', '--dir' => 'link']);

            self::assertSame(SymfonyCommand::INVALID, $tester->getStatusCode());
            self::assertSame([], (array) glob($outside.'/*'));
        } finally {
            (new Filesystem())->remove($outside);
        }
    }

    public function test_rejects_null_byte_in_path(): void
    {
        $tester = $this->execute(['type' => 'command', 'name' => 'App\\Command\\DoSomething', '--dir' => "src/\0exploit"]);

        self::assertSame(SymfonyCommand::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('invalid characters', self::display($tester));
    }

    public function test_reports_a_directory_that_cannot_be_created(): void
    {
        $this->writeComposerJson(['App\\' => 'src/']);
        file_put_contents($this->projectDir.'/src/Command', 'a file, not a directory');

        $tester = $this->execute(['type' => 'command', 'name' => 'App\\Command\\DoSomething', '--dir' => 'src']);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Unable to create directory', self::display($tester));
    }

    public function test_invalid_type_displays_error(): void
    {
        $tester = $this->execute(['type' => 'unknown', 'name' => 'App\\Message']);

        self::assertSame(SymfonyCommand::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Supported types are: command, query, event.', self::display($tester));
    }

    /**
     * @param array<string, string|bool> $input
     */
    private function execute(array $input): CommandTester
    {
        $kernel = self::createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->projectDir);

        $tester = new CommandTester(new GenerateMessageCommand($kernel));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @param array<string, string|list<string>> $psr4
     * @param array<string, string|list<string>> $psr4Dev
     */
    private function writeComposerJson(array $psr4, array $psr4Dev = []): void
    {
        file_put_contents($this->projectDir.'/composer.json', json_encode([
            'autoload' => ['psr-4' => $psr4],
            'autoload-dev' => ['psr-4' => $psr4Dev],
        ], JSON_THROW_ON_ERROR));
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->projectDir.'/'.$relativePath);
        self::assertIsString($contents);

        return $contents;
    }

    /**
     * SymfonyStyle wraps long lines; collapse whitespace so assertions do not depend on the terminal width.
     */
    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
