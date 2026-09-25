<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;
use Symfony\Component\Console\Style\SymfonyStyle;

use function defined;
use function sprintf;

use const SIGINT;
use const SIGTERM;

/**
 * @internal
 */
#[AsCommand(
    name: 'somework:cqrs:outbox:setup',
    description: 'Create the outbox table, or add the columns a table of an earlier version lacks.',
)]
final class OutboxSetupCommand extends Command implements SignalableCommandInterface
{
    private ?OutputInterface $output = null;

    public function __construct(private readonly OutboxStorage $outboxStorage)
    {
        parent::__construct();
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return defined('SIGTERM') && SignalRegistry::isSupported() ? [SIGTERM, SIGINT] : [];
    }

    /**
     * Stops right away (e.g. a deploy job that is terminated) and says so: exiting with 0 would let
     * the deployment go on as if the table was ready. What was done so far stays; the next setup
     * continues from there. A statement that runs (e.g. the index build) is only interrupted once
     * it returns.
     */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int
    {
        $output = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;
        $output?->writeln(sprintf('<error>The outbox table setup was stopped by signal %d; run it again.</error>', $signal));

        return 128 + $signal;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;
        $io = new SymfonyStyle($input, $output);

        if (!$this->outboxStorage instanceof DbalOutboxStorage) {
            $io->error(sprintf('The outbox storage (%s) is not the DBAL storage; create its schema yourself.', $this->outboxStorage::class));

            return self::FAILURE;
        }

        try {
            $this->outboxStorage->setup(static fn () => $io->note('Another process is setting up the outbox table (or the database session of a setup that was stopped is still at work); waiting for it, for at most 10 minutes.'));
        } catch (\Throwable $exception) {
            // e.g. the database is down, or the table cannot be changed: exit with 1 and say why.
            $io->error(sprintf('The outbox table could not be set up: %s', $exception->getMessage()));

            return self::FAILURE;
        }

        $io->success('The outbox table is ready.');

        return self::SUCCESS;
    }
}
