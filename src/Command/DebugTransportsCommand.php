<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use SomeWork\CqrsBundle\Support\TransportMappingProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function implode;
use function sprintf;

use const PHP_EOL;

#[AsCommand(
    name: 'somework:cqrs:debug-transports',
    description: 'Inspect Messenger transport routing for CQRS messages.',
)]
final class DebugTransportsCommand extends Command
{
    /**
     * @var array<string, string>
     */
    private const BUS_LABELS = [
        'command' => 'Command',
        'command_async' => 'Command (async)',
        'query' => 'Query',
        'event' => 'Event',
        'event_async' => 'Event (async)',
    ];

    public function __construct(
        private readonly TransportMappingProvider $mappingProvider,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(['Bus', 'Default Transports', 'Explicit Overrides']);

        foreach (self::BUS_LABELS as $key => $label) {
            $mapping = $this->mappingProvider->forBus($key);

            $defaults = $this->formatTransports($mapping['default']);
            $overrides = $this->formatOverrides($mapping['map']);

            $table->addRow([$label, $defaults, $overrides]);
        }

        $table->render();

        return self::SUCCESS;
    }

    /**
     * @param list<string> $transports
     */
    private function formatTransports(array $transports): string
    {
        if ([] === $transports) {
            return 'None';
        }

        return implode(', ', $transports);
    }

    /**
     * @param array<class-string, list<string>> $overrides
     */
    private function formatOverrides(array $overrides): string
    {
        if ([] === $overrides) {
            return 'None';
        }

        $rows = [];
        foreach ($overrides as $message => $transports) {
            $rows[] = sprintf('%s => %s', $message, $this->formatTransports($transports));
        }

        return implode(PHP_EOL, $rows);
    }
}
