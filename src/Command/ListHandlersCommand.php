<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use SomeWork\CqrsBundle\Registry\HandlerDescriptor;
use SomeWork\CqrsBundle\Registry\HandlerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'somework:cqrs:list',
    description: 'List CQRS commands, queries, and events registered in Messenger.',
)]
final class ListHandlersCommand extends Command
{
    public function __construct(private readonly HandlerRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, description: 'Filter by message type (command, query, event).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var array<int, string>|string|null $requestedTypes */
        $requestedTypes = $input->getOption('type');
        $types = $this->normaliseTypes($requestedTypes);

        $rows = [];
        foreach ($types as $type) {
            $descriptors = $this->registry->byType($type);
            foreach ($descriptors as $descriptor) {
                $rows[] = $this->formatDescriptor($descriptor);
            }
        }

        if ([] === $rows) {
            $io->warning('No CQRS handlers were found for the given filters.');

            return self::SUCCESS;
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]
        );

        $table = new Table($output);
        $table->setHeaders(['Type', 'Message', 'Handler', 'Service Id', 'Bus']);
        $table->setRows($rows);
        $table->render();

        return self::SUCCESS;
    }

    /**
     * @param array<int, string>|string|null $requested
     *
     * @return list<'command'|'query'|'event'>
     */
    private function normaliseTypes(array|string|null $requested): array
    {
        $available = ['command', 'query', 'event'];

        if (null === $requested || [] === $requested) {
            return $available;
        }

        if (is_string($requested)) {
            $requested = [$requested];
        }

        $types = [];
        foreach ($requested as $type) {
            $type = strtolower($type);
            if (in_array($type, $available, true)) {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private function formatDescriptor(HandlerDescriptor $descriptor): array
    {
        return [
            ucfirst($descriptor->type),
            $this->registry->getDisplayName($descriptor),
            $descriptor->handlerClass,
            $descriptor->serviceId,
            $descriptor->bus ?? 'default',
        ];
    }
}
