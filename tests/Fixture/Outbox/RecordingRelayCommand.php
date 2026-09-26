<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use SomeWork\CqrsBundle\Outbox\Relay\RelayOnTerminateSubscriber;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

/**
 * Stands in for somework:cqrs:outbox:relay: records the options of each run, can store messages in
 * turn (a handler the relay ran stored one), and exits with $exitCode.
 */
final class RecordingRelayCommand extends Command
{
    /** @var list<string> */
    public array $runs = [];

    public ?RelayOnTerminateSubscriber $subscriber = null;

    public int $storesDuringRun = 0;

    public int $exitCode = self::SUCCESS;

    public function __construct()
    {
        parent::__construct('somework:cqrs:outbox:relay');
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED);
        $this->addOption('wait-for-lock', null, InputOption::VALUE_REQUIRED);
        $this->addOption('no-reset', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->runs[] = sprintf('--limit=%s --wait-for-lock=%s%s', $input->getOption('limit'), $input->getOption('wait-for-lock'), true === $input->getOption('no-reset') ? ' --no-reset' : '');
        if ($this->storesDuringRun-- > 0) {
            $this->subscriber?->stored();
        }

        return $this->exitCode;
    }
}
