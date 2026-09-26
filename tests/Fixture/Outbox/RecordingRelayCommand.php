<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use SomeWork\CqrsBundle\Outbox\Relay\RelayOnTerminateSubscriber;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Stands in for somework:cqrs:outbox:relay: records the --limit of each run, and can store
 * messages in turn (a handler the relay ran stored one).
 */
final class RecordingRelayCommand extends Command
{
    /** @var list<string> */
    public array $runs = [];

    public ?RelayOnTerminateSubscriber $subscriber = null;

    public int $storesDuringRun = 0;

    public function __construct()
    {
        parent::__construct('somework:cqrs:outbox:relay');
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->runs[] = (string) $input->getOption('limit');
        if ($this->storesDuringRun-- > 0) {
            $this->subscriber?->stored();
        }

        return self::SUCCESS;
    }
}
