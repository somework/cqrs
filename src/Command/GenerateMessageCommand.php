<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use InvalidArgumentException;
use RuntimeException;
use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Attribute\AsQueryHandler;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\CommandHandler;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\EventHandler;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Contract\QueryHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

use function dirname;
use function is_string;
use function realpath;
use function sprintf;
use function str_contains;
use function strlen;

/** @internal */
#[AsCommand(
    name: 'somework:cqrs:generate',
    description: 'Generate a CQRS message and handler skeleton.',
)]
final class GenerateMessageCommand extends SymfonyCommand
{
    /** @var array<string, class-string> */
    private const MESSAGE_INTERFACES = [
        'command' => Command::class,
        'query' => Query::class,
        'event' => Event::class,
    ];

    /** @var array<string, class-string> */
    private const HANDLER_INTERFACES = [
        'command' => CommandHandler::class,
        'query' => QueryHandler::class,
        'event' => EventHandler::class,
    ];

    /** @var array<string, class-string> */
    private const HANDLER_ATTRIBUTES = [
        'command' => AsCommandHandler::class,
        'query' => AsQueryHandler::class,
        'event' => AsEventHandler::class,
    ];

    public function __construct(private readonly KernelInterface $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::REQUIRED, 'Message type (command, query, event).')
            ->addArgument('name', InputArgument::REQUIRED, 'Fully-qualified class name of the message.')
            ->addOption('handler', null, InputOption::VALUE_OPTIONAL, 'Fully-qualified class name of the handler. Defaults to <MessageName>Handler.')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Base directory where classes should be generated. Defaults to <project>/src.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite files if they already exist.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $type = strtolower((string) $input->getArgument('type'));
        if (!isset(self::MESSAGE_INTERFACES[$type])) {
            $io->error('Supported types are: command, query, event.');

            return self::FAILURE;
        }

        $messageClass = (string) $input->getArgument('name');
        $handlerOption = $input->getOption('handler');
        $handlerClass = is_string($handlerOption) && '' !== $handlerOption ? $handlerOption : $messageClass.'Handler';
        $dirOption = $input->getOption('dir');
        $baseDir = is_string($dirOption) && '' !== $dirOption ? $dirOption : $this->kernel->getProjectDir().'/src';
        $force = (bool) $input->getOption('force');

        [$messageNamespace, $messageShortClass] = $this->splitClass($messageClass);
        [$handlerNamespace, $handlerShortClass] = $this->splitClass($handlerClass);

        $messagePath = $this->classToPath($baseDir, $messageClass);
        $handlerPath = $this->classToPath($baseDir, $handlerClass);

        try {
            $this->validateTargetPath($baseDir, $this->kernel->getProjectDir());
            $this->dumpFile($messagePath, $this->generateMessage($type, $messageNamespace, $messageShortClass), $force);
            $this->dumpFile($handlerPath, $this->generateHandler($type, $messageClass, $handlerNamespace, $handlerShortClass), $force);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $io->success(sprintf('Generated %s and %s.', $this->relativePath($messagePath), $this->relativePath($handlerPath)));

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitClass(string $class): array
    {
        $parts = explode('\\', $class);
        $short = array_pop($parts);
        $namespace = implode('\\', $parts);

        return [$namespace, $short];
    }

    private function classToPath(string $baseDir, string $class): string
    {
        $baseDir = rtrim($baseDir, '/');

        return $baseDir.'/'.str_replace('\\', '/', $class).'.php';
    }

    private function validateTargetPath(string $path, string $projectDir): void
    {
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Target path contains invalid characters.');
        }

        $realProjectDir = realpath($projectDir);
        if (false === $realProjectDir) {
            throw new InvalidArgumentException(sprintf('Project directory "%s" does not exist.', $projectDir));
        }

        $checkPath = $path;
        while (!file_exists($checkPath)) {
            $checkPath = dirname($checkPath);
            if ('.' === $checkPath || '' === $checkPath) {
                throw new InvalidArgumentException('Target directory must be within the project directory.');
            }
        }

        $realPath = realpath($checkPath);
        if (false === $realPath || !str_starts_with($realPath, $realProjectDir)) {
            throw new InvalidArgumentException('Target directory must be within the project directory.');
        }
    }

    private function dumpFile(string $path, string $contents, bool $force): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $dir));
        }

        if (!$force && file_exists($path)) {
            throw new RuntimeException(sprintf('File "%s" already exists. Use --force to overwrite.', $path));
        }

        if (false === file_put_contents($path, $contents)) {
            throw new RuntimeException(sprintf('Unable to write file "%s".', $path));
        }
    }

    private function generateMessage(string $type, string $namespace, string $class): string
    {
        $interface = self::MESSAGE_INTERFACES[$type];
        $shortInterface = basename(str_replace('\\', '/', $interface));

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            sprintf('namespace %s;', '' !== $namespace ? $namespace : 'App'),
            '',
            sprintf('use %s;', $interface),
            '',
            sprintf('final class %s implements %s', $class, $shortInterface),
            '{',
            '    public function __construct(',
            '        public readonly string $id,',
            '        // TODO: Add message properties.',
            '    ) {',
            '    }',
            '}',
            '',
        ];

        return implode("\n", $lines);
    }

    private function generateHandler(string $type, string $messageClass, string $namespace, string $class): string
    {
        $interface = self::HANDLER_INTERFACES[$type];
        $attribute = self::HANDLER_ATTRIBUTES[$type];
        $shortInterface = basename(str_replace('\\', '/', $interface));
        $shortAttribute = basename(str_replace('\\', '/', $attribute));
        $shortMessage = basename(str_replace('\\', '/', $messageClass));

        $methodSignature = sprintf('    public function __invoke(%s $message): void', $shortMessage);
        $methodBody = [
            '        // TODO: Implement handler logic.',
        ];

        if ('query' === $type) {
            $methodSignature = sprintf('    public function __invoke(%s $message): mixed', $shortMessage);
            $methodBody = [
                "        throw new \\LogicException('Not implemented: replace with query result.');",
            ];
        }

        if ('event' === $type) {
            $methodSignature = sprintf('    public function __invoke(%s $message): void', $shortMessage);
            $methodBody = [
                '        // TODO: React to the event.',
            ];
        }

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            sprintf('namespace %s;', '' !== $namespace ? $namespace : 'App'),
            '',
            sprintf('use %s;', $attribute),
            sprintf('use %s;', $interface),
            sprintf('use %s;', $messageClass),
            '',
            sprintf('#[%s(%s::class)]', $shortAttribute, $shortMessage),
            sprintf('final class %s implements %s', $class, $shortInterface),
            '{',
            '    public function __construct(',
            '        // TODO: Inject dependencies.',
            '    ) {',
            '    }',
            '',
            $methodSignature,
            '    {',
        ];

        $lines = array_merge($lines, $methodBody);

        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function relativePath(string $path): string
    {
        $projectDir = rtrim($this->kernel->getProjectDir(), '/');
        $normalised = str_replace('\\', '/', $path);

        if (str_starts_with($normalised, $projectDir.'/')) {
            return substr($normalised, strlen($projectDir) + 1);
        }

        return $normalised;
    }
}
