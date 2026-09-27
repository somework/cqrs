<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use DateTimeImmutable;
use DateTimeZone;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function preg_match;
use function sprintf;
use function strtolower;

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

        // Only "<number> <unit>": a bare number would be parsed as a time zone offset by DateTime.
        if (!is_string($olderThan) || 1 !== preg_match('/^\s*(\d+)\s*(second|minute|hour|day|week|month|year)s?\s*$/i', $olderThan, $matches)) {
            $io->error('"--older-than" must be a relative age such as "7 days" or "12 hours".');

            return self::INVALID;
        }

        $before = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify(sprintf('-%d %s', (int) $matches[1], strtolower($matches[2])));

        $deleted = $this->outboxStorage->purgePublished($before);

        $io->success(sprintf('Deleted %d published message(s) older than %s.', $deleted, $before->format(DATE_ATOM)));

        return self::SUCCESS;
    }
}
