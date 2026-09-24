<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use DateTimeImmutable;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function sprintf;

use const DATE_ATOM;

/**
 * @internal
 */
#[AsCommand(
    name: 'somework:cqrs:outbox:purge',
    description: 'Delete outbox messages that were published before a given age.',
)]
final class OutboxPurgeCommand extends Command
{
    public function __construct(private readonly OutboxStorage $outboxStorage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Age of the published messages to delete, as a relative date (e.g. "7 days")', '7 days');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $olderThan = $input->getOption('older-than');

        try {
            $before = is_string($olderThan) && '' !== $olderThan ? new DateTimeImmutable('-'.$olderThan) : null;
        } catch (\Exception) {
            $before = null;
        }

        if (null === $before || $before > new DateTimeImmutable()) {
            $io->error('"--older-than" must be a positive relative date such as "7 days" or "12 hours".');

            return self::INVALID;
        }

        $deleted = $this->outboxStorage->purgePublished($before);

        $io->success(sprintf('Deleted %d published message(s) older than %s.', $deleted, $before->format(DATE_ATOM)));

        return self::SUCCESS;
    }
}
