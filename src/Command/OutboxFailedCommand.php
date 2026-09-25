<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use SomeWork\CqrsBundle\Contract\Outbox\FailedOutboxMessages;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\FailedOutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\Signing\OutboxSigner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_key_exists;
use function array_map;
use function array_values;
use function assert;
use function count;
use function filter_var;
use function hash_equals;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function strtolower;
use function substr;
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

    /**
     * @param OutboxStorage     $outboxStorage The storage behind any decorator (see OutboxStoragePass)
     * @param OutboxSigner|null $signer        Signs requeued messages with --sign (outbox.signing)
     */
    public function __construct(
        private readonly OutboxStorage $outboxStorage,
        private readonly ?OutboxSigner $signer = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('ids', InputArgument::IS_ARRAY, 'Ids of the messages to requeue (with --requeue); all given-up messages when omitted')
            ->addOption('requeue', null, InputOption::VALUE_NONE, 'Requeue the messages with a fresh attempt counter instead of listing them')
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'With --requeue and message ids: send the messages to this transport instead of the stored one')
            ->addOption('sign', null, InputOption::VALUE_NONE, 'With --requeue and message ids: sign the messages with the current secret (they were unsigned, or signed with another secret)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of messages to list', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $storage = $this->outboxStorage;
        if (!$storage instanceof FailedOutboxMessages) {
            $io->error(sprintf('The outbox storage (%s) does not implement %s; inspect its failed messages yourself.', $storage::class, FailedOutboxMessages::class));

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
        // The stored transport names are overwritten: never for every given-up message at once.
        if (null !== $transport && [] === $ids) {
            $io->error('--transport needs the ids of the messages: it replaces their stored transport name, which cannot be undone.');

            return self::INVALID;
        }

        $sign = true === $input->getOption('sign');
        if ($sign && (true !== $input->getOption('requeue') || [] === $ids)) {
            $io->error('--sign needs --requeue and the ids of the messages: sign only rows you checked.');

            return self::INVALID;
        }
        if ($sign && null === $this->signer) {
            $io->error('Outbox signing is disabled ("somework_cqrs.outbox.signing.enabled"): there is nothing to sign with.');

            return self::FAILURE;
        }

        try {
            if ($sign) {
                return $this->signAndRequeue($io, $input, $storage, $ids, $transport);
            }

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
    private function requeue(SymfonyStyle $io, FailedOutboxMessages $storage, array $ids, ?string $transport, ?\Closure $sign = null): int
    {
        $requeued = $storage->requeueFailed($ids, $transport, $sign);
        $io->success(sprintf('Requeued %d message(s)%s; the next relay run sends them.', $requeued, null === $transport ? '' : sprintf(' to the transport "%s"', $transport)));

        if ([] !== $ids && $requeued < count($ids)) {
            $io->warning(sprintf('%d of the %d given message(s) were not requeued: they do not exist, were published, or have not been given up.', count($ids) - $requeued, count($ids)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * A signature makes the relay decode the row (unserialize() with PHP's serializer): the rows
     * are shown first, and in an interactive terminal the operator confirms.
     *
     * @param list<string> $ids
     */
    private function signAndRequeue(SymfonyStyle $io, InputInterface $input, FailedOutboxMessages $storage, array $ids, ?string $transport): int
    {
        assert(null !== $this->signer);
        $signer = $this->signer;

        $failed = $storage->fetchFailed(count($ids), $ids);
        if ([] === $failed) {
            $io->warning('None of the given messages has been given up: nothing was signed.');

            return self::FAILURE;
        }

        $io->text('These rows will be signed with the current secret, so the relay decodes them (with the PHP serializer: unserializes them). Only sign rows your application stored:');
        $io->table(
            ['Id', 'Type header', 'Class in the body', 'Body', 'Transport', 'Last error'],
            array_map(static fn (FailedOutboxMessage $message): array => [
                self::printable($message->id),
                self::printable($message->messageType ?? '-'),
                self::printable($message->bodyClass ?? '-'),
                null === $message->digest ? '?' : 'sha256 '.substr($message->digest, 0, 16),
                self::printable($message->transportName ?? '(routing)'),
                self::printable($message->lastError ?? ''),
            ], $failed),
        );

        // A forged row may carry a plausible header: it must agree with the body.
        foreach ($failed as $message) {
            if (null !== $message->messageType && null !== $message->bodyClass && $message->messageType !== $message->bodyClass) {
                $io->error(sprintf('The type header of message "%s" (%s) does not match the class in its body (%s): the row was not stored by this application. Nothing was signed.', self::printable($message->id), self::printable($message->messageType), self::printable($message->bodyClass)));

                return self::FAILURE;
            }
        }

        if ($input->isInteractive() && !$io->confirm('Sign and requeue them?', false)) {
            $io->note('Nothing was signed.');

            return self::FAILURE;
        }

        // Sign the rows as they were shown: a body or headers changed since then (by whoever can
        // write to the table) stop the command. Storages that do not report a digest are trusted.
        $reviewed = [];
        foreach ($failed as $message) {
            $reviewed[$message->id] = $message->digest;
        }

        $signed = 0;
        $changed = null;
        try {
            return $this->requeue($io, $storage, $ids, $transport, static function (OutboxMessage $message) use ($signer, $reviewed, &$signed, &$changed): string {
                if (!array_key_exists($message->id, $reviewed) || (null !== $reviewed[$message->id] && !hash_equals($reviewed[$message->id], FailedOutboxMessage::digest($message->body, $message->headers)))) {
                    $changed = $message->id;

                    throw new \RuntimeException(sprintf('Message "%s" changed after it was listed.', $message->id));
                }
                ++$signed;

                return $signer->sign($message);
            });
        } catch (\Throwable $exception) {
            if (null === $changed) {
                throw $exception;
            }

            $io->error(sprintf('Message "%s" changed after it was listed: it was not signed, nor were the messages after it%s. Run the command again.', self::printable($changed), 0 === $signed ? '' : sprintf(' (%d message(s) before it were signed and requeued)', $signed)));

            return self::FAILURE;
        }
    }

    /**
     * @param list<string> $ids
     */
    private function list(SymfonyStyle $io, InputInterface $input, FailedOutboxMessages $storage, array $ids): int
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

        $this->table($io, $failed);
        $io->note('Fix the cause, then run this command with --requeue (optionally followed by message ids).');

        return self::SUCCESS;
    }

    /**
     * @param list<FailedOutboxMessage> $failed
     */
    private function table(SymfonyStyle $io, array $failed): void
    {
        $io->table(
            ['Id', 'Message', 'Transport', 'Created', 'Given up', 'Attempts', 'Last error'],
            array_map(static fn (FailedOutboxMessage $message): array => [
                self::printable($message->id),
                self::printable($message->messageType ?? '?'),
                self::printable($message->transportName ?? '(routing)'),
                $message->createdAt->format(DATE_ATOM),
                $message->failedAt->format(DATE_ATOM),
                $message->attempts,
                self::printable($message->lastError ?? ''),
            ], $failed),
        );
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
