<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_map;
use function array_values;
use function count;
use function filter_var;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function strtolower;
use function trim;

use const DATE_ATOM;
use const FILTER_VALIDATE_INT;

/**
 * @internal
 */
#[AsCommand(
    name: 'somework:cqrs:outbox:failed',
    description: 'List the outbox messages the relay gave up on, or hand them back to the relay.',
)]
final class OutboxFailedCommand extends Command
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';

    public function __construct(private readonly OutboxStorage $outboxStorage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('ids', InputArgument::IS_ARRAY, 'Ids of the messages to requeue (with --requeue); all given-up messages when omitted')
            ->addOption('requeue', null, InputOption::VALUE_NONE, 'Requeue the messages with a fresh attempt counter instead of listing them')
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'With --requeue: send the messages to this transport instead of the stored one')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of messages to list', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $storage = $this->outboxStorage;
        if (!$storage instanceof DbalOutboxStorage) {
            $io->error(sprintf('The outbox storage (%s) is not the DBAL storage; inspect its failed messages yourself.', $storage::class));

            return self::FAILURE;
        }

        $ids = $input->getArgument('ids');
        // Stored lowercase (UUIDs are compared as text on some platforms).
        $ids = is_array($ids) ? array_values(array_map(static fn (mixed $id): string => strtolower((string) $id), $ids)) : [];

        foreach ($ids as $id) {
            if (1 !== preg_match(self::UUID, $id)) {
                $io->error(sprintf('"%s" is not an outbox message id (a UUID).', $id));

                return self::INVALID;
            }
        }

        $transport = $input->getOption('transport');
        if (null !== $transport && (!is_string($transport) || '' === trim($transport) || true !== $input->getOption('requeue'))) {
            $io->error('--transport needs a transport name and --requeue.');

            return self::INVALID;
        }

        try {
            return true === $input->getOption('requeue') ? $this->requeue($io, $storage, $ids, $transport) : $this->list($io, $input, $storage, $ids);
        } catch (\Throwable $exception) {
            // e.g. the database is down: exit with 1 and say why, instead of the driver's error code.
            $io->error(sprintf('The outbox storage failed: %s', $exception->getMessage()));

            return self::FAILURE;
        }
    }

    /**
     * @param list<string> $ids
     */
    private function requeue(SymfonyStyle $io, DbalOutboxStorage $storage, array $ids, ?string $transport): int
    {
        $requeued = $storage->requeueFailed($ids, $transport);
        $io->success(sprintf('Requeued %d message(s)%s; the next relay run sends them.', $requeued, null === $transport ? '' : sprintf(' to the transport "%s"', $transport)));

        if ([] !== $ids && $requeued < count($ids)) {
            $io->warning(sprintf('%d of the %d given message(s) were not requeued: they do not exist, were published, or have not been given up.', count($ids) - $requeued, count($ids)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param list<string> $ids
     */
    private function list(SymfonyStyle $io, InputInterface $input, DbalOutboxStorage $storage, array $ids): int
    {
        if ([] !== $ids) {
            $io->error('Message ids are only accepted together with --requeue.');

            return self::INVALID;
        }

        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if (false === $limit || $limit < 1) {
            $io->error('Limit must be a positive integer.');

            return self::INVALID;
        }

        $failed = $storage->fetchFailed($limit);
        if ([] === $failed) {
            $io->success('The relay has not given up on any message.');

            return self::SUCCESS;
        }

        $io->table(
            ['Id', 'Transport', 'Created', 'Given up', 'Attempts', 'Last error'],
            array_map(static fn (array $message): array => [
                $message['id'],
                self::printable($message['transport_name'] ?? '(routing)'),
                $message['created_at']->format(DATE_ATOM),
                $message['failed_at']->format(DATE_ATOM),
                $message['attempts'],
                self::printable($message['last_error'] ?? ''),
            ], $failed),
        );
        $io->note('Fix the cause, then run this command with --requeue (optionally followed by message ids).');

        return self::SUCCESS;
    }

    /**
     * Text read from the table, without control characters: escape sequences stored in a row
     * would reach the terminal of the operator.
     */
    private static function printable(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F\x{80}-\x{9F}]+/u', ' ', mb_scrub($text, 'UTF-8'));
    }
}
