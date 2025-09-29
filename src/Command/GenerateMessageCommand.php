<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

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
use function sprintf;
use function strlen;

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
        $handlerClass = (string) ($input->getOption('handler') ?: $messageClass.'Handler');
        $baseDir = (string) ($input->getOption('dir') ?: $this->kernel->getProjectDir().'/src');
        $force = (bool) $input->getOption('force');

        [$messageNamespace, $messageShortClass] = $this->splitClass($messageClass);
        [$handlerNamespace, $handlerShortClass] = $this->splitClass($handlerClass);

        $messagePath = $this->classToPath($baseDir, $messageClass);
        $handlerPath = $this->classToPath($baseDir, $handlerClass);

        try {
            $this->dumpFile($messagePath, $this->generateMessage($type, $messageNamespace, $messageShortClass), $force);
            $this->dumpFile($handlerPath, $this->generateHandler($type, $messageClass, $handlerNamespace, $handlerShortClass), $force);
        } catch (RuntimeException $exception) {
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

        return [$namespace, $short ?? $class];
    }

    private function classToPath(string $baseDir, string $class): string
    {
        $baseDir = rtrim($baseDir, '/');

        return $baseDir.'/'.str_replace('\\', '/', $class).'.php';
    }

    private function dumpFile(string $path, string $contents, bool $force): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        if (!$force && file_exists($path)) {
            throw new RuntimeException(sprintf('File "%s" already exists. Use --force to overwrite.', $path));
        }

        file_put_contents($path, $contents);
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
            sprintf('namespace %s;', $namespace ?: 'App'),
            '',
            sprintf('use %s;', $interface),
            '',
            sprintf('final class %s implements %s', $class, $shortInterface),
            '{',
            '    public function __construct()',
            '    {',
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

        $methodSignature = sprintf('    public function __invoke(%s $message): mixed', $shortMessage);
        $methodBody = [
            '        // TODO: Implement handler logic.',
            '',
            '        return null;',
        ];

        if ('query' === $type) {
            $methodBody = [
                '        // TODO: Return the query result.',
                '',
                '        return null;',
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
            sprintf('namespace %s;', $namespace ?: 'App'),
            '',
            sprintf('use %s;', $attribute),
            sprintf('use %s;', $interface),
            sprintf('use %s;', $messageClass),
            '',
            sprintf('#[%s(%s::class)]', $shortAttribute, $shortMessage),
            sprintf('final class %s implements %s', $class, $shortInterface),
            '{',
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
