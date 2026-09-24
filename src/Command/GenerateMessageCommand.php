<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use InvalidArgumentException;
use RuntimeException;
use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Attribute\AsQueryHandler;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpKernel\KernelInterface;

use function array_pop;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function ltrim;
use function mkdir;
use function preg_match;
use function realpath;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;

use const JSON_THROW_ON_ERROR;

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
    private const HANDLER_ATTRIBUTES = [
        'command' => AsCommandHandler::class,
        'query' => AsQueryHandler::class,
        'event' => AsEventHandler::class,
    ];

    private const CLASS_NAME_PATTERN = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)+$/';

    /** Words that cannot be used as a class name. */
    private const RESERVED_CLASS_NAMES = [
        '__halt_compiler', 'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class',
        'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty',
        'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends',
        'false', 'final', 'finally', 'float', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if',
        'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'int', 'interface', 'isset', 'iterable',
        'list', 'match', 'mixed', 'namespace', 'never', 'new', 'null', 'object', 'or', 'parent', 'print', 'private',
        'protected', 'public', 'readonly', 'require', 'require_once', 'return', 'self', 'static', 'string', 'switch',
        'throw', 'trait', 'true', 'try', 'unset', 'use', 'var', 'void', 'while', 'xor', 'yield',
    ];

    public function __construct(private readonly KernelInterface $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::REQUIRED, 'Message type (command, query, event).')
            ->addArgument('name', InputArgument::REQUIRED, 'Fully-qualified class name of the message, e.g. "App\\Command\\ShipOrder".')
            ->addOption('handler', null, InputOption::VALUE_REQUIRED, 'Fully-qualified class name of the handler. Defaults to <MessageName>Handler.')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory of the PSR-4 root the classes belong to (relative to the project directory). Defaults to the directory mapped in composer.json.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite files if they already exist.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $type = strtolower((string) $input->getArgument('type'));
        if (!isset(self::MESSAGE_INTERFACES[$type])) {
            $io->error('Supported types are: command, query, event.');

            return self::INVALID;
        }

        $projectDir = Path::canonicalize($this->kernel->getProjectDir());
        $handlerOption = $input->getOption('handler');
        $dirOption = $input->getOption('dir');
        $force = (bool) $input->getOption('force');

        try {
            $messageClass = self::normaliseClassName((string) $input->getArgument('name'));
            $handlerClass = self::normaliseClassName(is_string($handlerOption) && '' !== $handlerOption ? $handlerOption : $messageClass.'Handler');

            if (strtolower($messageClass) === strtolower($handlerClass)) {
                throw new InvalidArgumentException('The handler class must differ from the message class.');
            }

            $baseDir = is_string($dirOption) && '' !== $dirOption ? $dirOption : null;
            if (null !== $baseDir && str_contains($baseDir, "\0")) {
                throw new InvalidArgumentException('Target path contains invalid characters.');
            }

            $psr4 = $this->psr4Prefixes($projectDir);
            [$messagePath, $messageAutoloaded] = $this->classToPath($projectDir, $baseDir, $psr4, $messageClass);
            [$handlerPath, $handlerAutoloaded] = $this->classToPath($projectDir, $baseDir, $psr4, $handlerClass);

            foreach ([$messagePath, $handlerPath] as $path) {
                self::assertWithinProject($path, $projectDir);
            }
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return self::INVALID;
        }

        try {
            // Check every target first so a failure never leaves half of the skeleton behind.
            foreach ([$messagePath, $handlerPath] as $path) {
                if (!$force && file_exists($path)) {
                    throw new RuntimeException(sprintf('File "%s" already exists. Use --force to overwrite.', $this->relativePath($path)));
                }
            }

            $this->dumpFile($messagePath, $this->generateMessage($type, $messageClass));
            $this->dumpFile($handlerPath, $this->generateHandler($type, $messageClass, $handlerClass));
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $io->success(sprintf('Generated %s and %s.', $this->relativePath($messagePath), $this->relativePath($handlerPath)));

        if (!$messageAutoloaded || !$handlerAutoloaded) {
            $io->warning('The generated classes are not covered by a PSR-4 prefix in composer.json, so Composer cannot autoload them. Add an "autoload.psr-4" entry for their namespace.');
        }

        return self::SUCCESS;
    }

    private static function normaliseClassName(string $class): string
    {
        $class = ltrim($class, '\\');

        if (1 !== preg_match(self::CLASS_NAME_PATTERN, $class)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid fully-qualified class name; expected something like "App\\Command\\ShipOrder".', $class));
        }

        $shortName = self::shortName($class);
        if (in_array(strtolower($shortName), self::RESERVED_CLASS_NAMES, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is a reserved word and cannot be used as a class name.', $shortName));
        }

        return $class;
    }

    /**
     * @return array<string, string> PSR-4 prefixes (with trailing backslash; "" is the fallback) mapped to their first directory
     */
    private function psr4Prefixes(string $projectDir): array
    {
        $composerFile = $projectDir.'/composer.json';
        if (!is_file($composerFile)) {
            return [];
        }

        try {
            $composer = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $prefixes = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            $mapping = is_array($composer) && is_array($composer[$section] ?? null) ? ($composer[$section]['psr-4'] ?? []) : [];

            foreach (is_array($mapping) ? $mapping : [] as $prefix => $dirs) {
                $dir = is_array($dirs) ? ($dirs[0] ?? null) : $dirs;

                if (is_string($prefix) && is_string($dir)) {
                    $prefixes[$prefix] ??= $dir;
                }
            }
        }

        return $prefixes;
    }

    /**
     * Resolves the file of a class from the longest matching PSR-4 prefix; "--dir" replaces the
     * directory mapped to that prefix. Classes outside every prefix go to "<dir>/<Full/Class/Path>.php".
     *
     * @param array<string, string> $psr4
     *
     * @return array{0: string, 1: bool} the path and whether a PSR-4 prefix covers the class
     */
    private function classToPath(string $projectDir, ?string $baseDir, array $psr4, string $class): array
    {
        $matchedPrefix = null;
        foreach ($psr4 as $prefix => $dir) {
            if (str_starts_with($class, $prefix) && (null === $matchedPrefix || strlen($prefix) > strlen($matchedPrefix))) {
                $matchedPrefix = $prefix;
            }
        }

        $directory = Path::makeAbsolute($baseDir ?? (null !== $matchedPrefix ? $psr4[$matchedPrefix] : 'src'), $projectDir);
        $relativeClass = null !== $matchedPrefix ? substr($class, strlen($matchedPrefix)) : $class;

        return [Path::join($directory, str_replace('\\', '/', $relativeClass).'.php'), null !== $matchedPrefix];
    }

    private static function assertWithinProject(string $path, string $projectDir): void
    {
        $realProjectDir = realpath($projectDir);
        if (false === $realProjectDir) {
            throw new InvalidArgumentException(sprintf('Project directory "%s" does not exist.', $projectDir));
        }

        // The nearest existing ancestor decides where the file really ends up (symlinks included).
        $existing = Path::getDirectory($path);
        while (!file_exists($existing) && Path::getDirectory($existing) !== $existing) {
            $existing = Path::getDirectory($existing);
        }
        $realExisting = realpath($existing);

        if (!Path::isBasePath($projectDir, $path) || false === $realExisting || !Path::isBasePath($realProjectDir, $realExisting)) {
            throw new InvalidArgumentException(sprintf('Target "%s" must be within the project directory.', $path));
        }
    }

    private function dumpFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $dir));
        }

        if (false === @file_put_contents($path, $contents)) {
            throw new RuntimeException(sprintf('Unable to write file "%s".', $path));
        }
    }

    private function generateMessage(string $type, string $messageClass): string
    {
        $interface = self::MESSAGE_INTERFACES[$type];

        return implode("\n", [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            sprintf('namespace %s;', self::namespaceOf($messageClass)),
            '',
            sprintf('use %s;', $interface),
            '',
            sprintf('final class %s implements %s', self::shortName($messageClass), self::shortName($interface)),
            '{',
            '    public function __construct(',
            '        public readonly string $id,',
            '        // TODO: Add message properties.',
            '    ) {',
            '    }',
            '}',
            '',
        ]);
    }

    private function generateHandler(string $type, string $messageClass, string $handlerClass): string
    {
        $attribute = self::HANDLER_ATTRIBUTES[$type];
        $handlerNamespace = self::namespaceOf($handlerClass);
        $handlerShortName = self::shortName($handlerClass);

        // Import the message unless it lives in the handler's namespace; alias it when its short
        // name clashes with the handler's.
        $messageAlias = self::shortName($messageClass);
        $imports = [$attribute];
        if (self::namespaceOf($messageClass) !== $handlerNamespace) {
            if (strtolower($messageAlias) === strtolower($handlerShortName)) {
                $messageAlias .= 'Message';
                $imports[] = sprintf('%s as %s', $messageClass, $messageAlias);
            } else {
                $imports[] = $messageClass;
            }
        }

        [$signature, $body] = match ($type) {
            // Commands may return a result to CommandBus::dispatchSync() callers.
            'command' => [
                sprintf('    public function __invoke(%s $command): mixed', $messageAlias),
                ['        // TODO: Implement the command.', '', '        return null;'],
            ],
            'query' => [
                sprintf('    public function __invoke(%s $query): mixed', $messageAlias),
                ["        throw new \\LogicException('Not implemented: return the query result.');"],
            ],
            default => [
                sprintf('    public function __invoke(%s $event): void', $messageAlias),
                ['        // TODO: React to the event.'],
            ],
        };

        $lines = ['<?php', '', 'declare(strict_types=1);', '', sprintf('namespace %s;', $handlerNamespace), ''];
        foreach ($imports as $import) {
            $lines[] = sprintf('use %s;', $import);
        }

        return implode("\n", [
            ...$lines,
            '',
            sprintf('#[%s(%s::class)]', self::shortName($attribute), $messageAlias),
            sprintf('final class %s', $handlerShortName),
            '{',
            '    public function __construct(',
            '        // TODO: Inject dependencies.',
            '    ) {',
            '    }',
            '',
            $signature,
            '    {',
            ...$body,
            '    }',
            '}',
            '',
        ]);
    }

    private static function namespaceOf(string $class): string
    {
        $parts = explode('\\', $class);
        array_pop($parts);

        return implode('\\', $parts);
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return false === $position ? $class : substr($class, $position + 1);
    }

    private function relativePath(string $path): string
    {
        $projectDir = $this->kernel->getProjectDir();

        return Path::isBasePath($projectDir, $path) ? Path::makeRelative($path, $projectDir) : $path;
    }
}
