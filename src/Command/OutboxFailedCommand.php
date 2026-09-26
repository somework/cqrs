<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use SomeWork\CqrsBundle\Contract\Outbox\FailedOutboxMessages;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\FailedOutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\Signing\OutboxSigner;
use SomeWork\CqrsBundle\Outbox\Signing\SignableBody;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Envelope;

use function array_key_exists;
use function array_map;
use function array_unique;
use function array_values;
use function assert;
use function count;
use function filter_var;
use function hash_equals;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function ltrim;
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
            ->addArgument('ids', InputArgument::IS_ARRAY, 'Ids of the messages to requeue (with --requeue; all given-up messages when omitted) or to delete (with --delete)')
            ->addOption('requeue', null, InputOption::VALUE_NONE, 'Requeue the messages with a fresh attempt counter instead of listing them')
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'With --requeue and message ids: send the messages to this transport instead of the stored one')
            ->addOption('sign', null, InputOption::VALUE_NONE, 'With --requeue and message ids: sign the messages with the current secret (they were unsigned, or signed with another secret)')
            ->addOption('allow-class', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'With --sign: a class or interface the bodies may instantiate besides the envelope, stamps, the message and the types of their properties')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Delete the given messages instead of listing them (rows that must not be sent, or personal data to erase)')
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

        if (true === $input->getOption('delete')) {
            if (true === $input->getOption('requeue') || true === $input->getOption('sign') || null !== $input->getOption('transport')) {
                $io->error('--delete cannot be combined with --requeue, --sign or --transport.');

                return self::INVALID;
            }
            if ([] === $ids) {
                $io->error('--delete needs the ids of the messages: it cannot be undone.');

                return self::INVALID;
            }

            try {
                return $this->delete($io, $input, $storage, $ids);
            } catch (\Throwable $exception) {
                $io->error(sprintf('The outbox storage failed: %s', $exception->getMessage()));

                return self::FAILURE;
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
        $allowedClasses = $input->getOption('allow-class');
        $allowedClasses = is_array($allowedClasses) ? array_values(array_map(static fn (mixed $class): string => ltrim((string) $class, '\\'), $allowedClasses)) : [];
        if ([] !== $allowedClasses && !$sign) {
            $io->error('--allow-class is only used with --sign.');

            return self::INVALID;
        }
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
                return $this->signAndRequeue($io, $input, $storage, $ids, $transport, $allowedClasses);
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
     * @param list<string> $allowedClasses
     */
    private function signAndRequeue(SymfonyStyle $io, InputInterface $input, FailedOutboxMessages $storage, array $ids, ?string $transport, array $allowedClasses): int
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
            ['Id', 'Type header', 'Class in the body', 'Classes the body instantiates', 'Body', 'Transport', 'Last error'],
            array_map(static fn (FailedOutboxMessage $message): array => [
                self::printable($message->id),
                self::printable($message->messageType ?? '-'),
                self::printable($message->bodyClass ?? '-'),
                match (true) {
                    null === $message->bodyClasses => '?',
                    null === $message->bodyClass && [] === $message->bodyClasses => 'not a PHP-serialized body: the type header names the class',
                    default => self::printable(implode(', ', $message->bodyClasses)),
                },
                null === $message->digest ? '?' : 'sha256 '.substr($message->digest, 0, 16),
                self::printable($message->transportName ?? '(routing)'),
                self::printable($message->lastError ?? ''),
            ], $failed),
        );

        // A row forged by someone without the secret may name a plausible class: the header must agree
        // with the body, and the body may only instantiate the kind of objects the application stores.
        foreach ($failed as $message) {
            if (null !== $message->messageType && null !== $message->bodyClass && $message->messageType !== $message->bodyClass) {
                $io->error(sprintf('The type header of message "%s" (%s) does not match the class in its body (%s): the row was not stored by this application. Nothing was signed.', self::printable($message->id), self::printable($message->messageType), self::printable($message->bodyClass)));

                return self::FAILURE;
            }

            if (null === $message->bodyClasses) {
                continue;
            }
            if ([] !== $message->customSerializedClasses) {
                $io->error(sprintf('The body of message "%s" contains %s, serialized with custom serialization (Serializable): the objects in its data cannot be checked, and a forged row may hide one there to run code when it is unserialized. Nothing was signed. Delete the row if your application did not store it.', self::printable($message->id), self::printable(implode(', ', $message->customSerializedClasses))));

                return self::FAILURE;
            }
            // An envelope whose message cannot be found was not written by the serializer.
            if (null === $message->bodyClass && in_array(Envelope::class, $message->bodyClasses, true)) {
                $io->error(sprintf('The message of the envelope in the body of message "%s" cannot be found: the row was not stored by this application. Nothing was signed.', self::printable($message->id)));

                return self::FAILURE;
            }
            $messageClass = $message->bodyClass ?? $message->messageType;
            // The serializers that read the type header (the Symfony serializer) instantiate the class it names.
            $classes = array_values(array_unique([...(null === $message->messageType ? [] : [$message->messageType]), ...$message->bodyClasses]));
            $untrusted = null === $messageClass ? $classes : SignableBody::untrustedClasses($messageClass, $classes, $allowedClasses);
            if ([] !== $untrusted) {
                $io->error(sprintf('The body of message "%s" instantiates %s, which %s neither the envelope, a stamp, a command, query or event%s nor a type declared by their properties: the row may have been forged to run code when it is unserialized. Nothing was signed. Delete the row if your application did not store it; if it did (e.g. an object in an untyped property), allow the class with --allow-class.', self::printable($message->id), self::printable(implode(', ', $untrusted)), 1 === count($untrusted) ? 'is' : 'are', null === $messageClass ? '' : sprintf(' (%s)', self::printable($messageClass))));

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
     * @param non-empty-list<string> $ids
     */
    private function delete(SymfonyStyle $io, InputInterface $input, FailedOutboxMessages $storage, array $ids): int
    {
        $failed = $storage->fetchFailed(count($ids), $ids);
        if ([] === $failed) {
            $io->warning('None of the given messages has been given up: nothing was deleted.');

            return self::FAILURE;
        }

        $this->table($io, $failed);
        if ($input->isInteractive() && !$io->confirm(sprintf('Delete these %d message(s)? This cannot be undone.', count($failed)), false)) {
            $io->note('Nothing was deleted.');

            return self::FAILURE;
        }

        $deleted = $storage->deleteFailed($ids);
        $io->success(sprintf('Deleted %d message(s).', $deleted));

        if ($deleted < count($ids)) {
            $io->warning(sprintf('%d of the %d given message(s) were not deleted: they do not exist, were published, or have not been given up.', count($ids) - $deleted, count($ids)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param list<string> $ids
     */
    private function list(SymfonyStyle $io, InputInterface $input, FailedOutboxMessages $storage, array $ids): int
    {
        if ([] !== $ids) {
            $io->error('Message ids are only accepted together with --requeue or --delete.');

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
     * Text read from the table, without control characters and with console formatter tags
     * escaped: escape sequences or tags such as <href=…> stored in a row would reach the terminal
     * of the operator.
     */
    private static function printable(string $text): string
    {
        return OutputFormatter::escape((string) preg_replace('/[\x00-\x1F\x7F\x{80}-\x{9F}]+/u', ' ', mb_scrub($text, 'UTF-8')));
    }
}
