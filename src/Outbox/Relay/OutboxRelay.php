<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox\Relay;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\RetryableException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\Command as CommandMessage;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\Signing\OutboxSigner;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\MessageDecodingFailedStamp;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function array_filter;
use function array_flip;
use function array_shift;
use function array_values;
use function bin2hex;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function mb_scrub;
use function mb_substr;
use function microtime;
use function min;
use function preg_replace;
use function random_bytes;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Relays unpublished outbox messages to their transports (at-least-once delivery): claims each
 * message before sending it, retries failing ones with a backoff, gives up after the last attempt,
 * and pauses a transport that keeps failing for the rest of the run.
 *
 * @internal
 */
final class OutboxRelay
{
    /** Consecutive send failures after which the other messages of that transport wait for the next run. */
    private const MAX_CONSECUTIVE_TRANSPORT_FAILURES = 3;

    /**
     * The same for a transport that accepted a message in this run: it is up, and failures are
     * more likely rejections of single messages (e.g. too large) than an outage.
     */
    private const MAX_CONSECUTIVE_FAILURES_OF_A_WORKING_TRANSPORT = 10;

    /** Seconds of consecutive failures after which even a transport that worked earlier in the run is paused (e.g. it went down and every send waits for a timeout). */
    private const MAX_FAILING_SECONDS = 10;

    /** A message whose transport fails (unreachable, or rejecting it) gets this many times max_attempts. */
    private const TRANSPORT_ATTEMPTS_FACTOR = 3;

    /** Delay before the second attempt; it doubles with every failure up to MAX_RETRY_DELAY. */
    private const RETRY_DELAY = 60;

    private const MAX_RETRY_DELAY = 3600;

    private const MAX_ERROR_LENGTH = 2000;

    /** Rows fetched at once: bodies can be large. */
    private const BATCH_SIZE = 50;

    /** Seconds after which the claims of a batch are renewed; a claim holds at least 60 seconds. */
    private const RENEW_SECONDS = 20;

    /** Seconds between two markPublished() calls for the messages sent in the meantime. */
    private const FLUSH_SECONDS = 2;

    /** The error of a message given up after an attempt the process did not finish. */
    private const INTERRUPTED = 'The last attempt did not finish (e.g. a PHP fatal error, running out of memory, a killed process or a lost database connection); the message may have been sent.';

    private const RELAYED = 'relayed';

    private const FAILED = 'failed';

    private const TRANSPORT_FAILED = 'transport_failed';

    private const CLAIMED_ELSEWHERE = 'claimed_elsewhere';

    /** Ends the run: the limit is reached, or a stop was requested. */
    private const END = 'end';

    /** Ends the run at once: RelayReporter::continueAfterMessage() returned false. */
    private const ABORT = 'abort';

    /** Identifies the claims of the current run. */
    private string $token = '';

    /** @var list<string> Sent messages not marked as published yet */
    private array $sent = [];

    private float $flushedAt = 0.0;

    private int $processed = 0;

    private int $relayed = 0;

    private int $failed = 0;

    private int $claimedElsewhere = 0;

    /** @var array<string, int> Keyed by transport name, "" for messages without one */
    private array $consecutiveTransportFailures = [];

    /** @var array<string, true> Transports that accepted a message in this run */
    private array $workingTransports = [];

    /** @var array<string, float> When the current series of failures of a transport began */
    private array $failingSince = [];

    /** @var list<string|null> Transports that failed too often in a row: their messages wait for the next run */
    private array $pausedTransports = [];

    /**
     * @param ContainerInterface|null  $buses       Buses keyed by message type ("command", "query", "event"); other messages use $messageBus
     * @param int                      $maxAttempts Attempts after which a failing message is given up (three times as many when its transport fails)
     * @param (\Closure(): float)|null $clock       Seconds since the epoch, microtime(true) by default (for tests)
     * @param ContainerInterface|null  $transports  Messenger's transports by name; a message stored for another transport is given up at once
     * @param OutboxSigner|null        $signer      Verifies the signature of every message before it is decoded (outbox.signing)
     */
    public function __construct(
        private readonly OutboxStorage $outboxStorage,
        private readonly SerializerInterface $serializer,
        private readonly MessageBusInterface $messageBus,
        private readonly ?ContainerInterface $buses = null,
        private readonly int $maxAttempts = 10,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?\Closure $clock = null,
        private readonly ?ContainerInterface $transports = null,
        private readonly ?OutboxSigner $signer = null,
        private readonly bool $acceptUnsigned = false,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException(sprintf('The maximum number of attempts must be at least 1, %d given.', $maxAttempts));
        }
    }

    /**
     * Relays up to $limit due messages.
     *
     * @throws \RuntimeException when the storage fails, which stops the run
     */
    public function run(int $limit, RelayReporter $reporter): RelayResult
    {
        $this->token = bin2hex(random_bytes(16));
        $this->sent = [];
        $this->flushedAt = $this->now();
        $this->processed = $this->relayed = $this->failed = $this->claimedElsewhere = 0;
        $this->consecutiveTransportFailures = $this->workingTransports = $this->failingSince = $this->pausedTransports = [];

        try {
            $aborted = $this->relay($limit, $reporter);
            $this->flush(true);
        } catch (\Throwable $exception) {
            // Keep what was sent before the failure from being sent again, if the storage still works.
            try {
                $this->flush(true);
            } catch (\Throwable) {
                // The first failure matters; the messages are sent again (at least once).
            }

            throw $exception;
        }

        return new RelayResult($this->processed, $this->relayed, $this->failed, $this->claimedElsewhere, $aborted);
    }

    /**
     * @return bool Whether the run was aborted
     */
    private function relay(int $limit, RelayReporter $reporter): bool
    {
        /** @var array<string, true> $seen */
        $seen = [];

        while ($this->processed < $limit && !$reporter->stopRequested()) {
            $requested = min($limit - $this->processed, self::BATCH_SIZE);
            $fresh = [];
            foreach ($this->outboxStorage->fetchUnpublished($requested, $this->pausedTransports) as $message) {
                // A storage that does not postpone attempted messages returns them again: each is tried once per run.
                if (isset($seen[$message->id]) || in_array($message->transportName, $this->pausedTransports, true)) {
                    continue;
                }
                $seen[$message->id] = true;
                $fresh[] = $message;
            }

            // A short batch does not mean that nothing else is due: the storage leaves out the rows an
            // overlapping relay claimed after they were chosen. Only a batch without new rows ends the run.
            if ([] === $fresh) {
                break;
            }

            // A message whose last attempt was interrupted (the process died, or lost the database)
            // is claimed and sent alone, so if it kills the process again, only it is blamed.
            $claims = [];
            $others = [];
            foreach ($fresh as $message) {
                if (null === $message->claimedAt) {
                    $others[] = $message;
                } else {
                    $claims[] = [$message];
                }
            }
            if ([] !== $others) {
                $claims[] = $others;
            }

            foreach ($claims as $messages) {
                $outcome = $this->relayClaim($messages, $limit, $reporter);
                if (self::ABORT === $outcome) {
                    return true;
                }
                if (self::END === $outcome) {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * Claims the messages, then attempts them one by one; releases the claims of those it did not
     * attempt (the run ended, or their transport was paused).
     *
     * @param list<OutboxMessage> $messages
     *
     * @return self::END|self::ABORT|null
     */
    private function relayClaim(array $messages, int $limit, RelayReporter $reporter): ?string
    {
        if ($this->processed >= $limit || $reporter->stopRequested()) {
            return self::END;
        }

        // A message claimed and sent alone before may have paused a transport of this batch.
        $messages = array_values(array_filter($messages, fn (OutboxMessage $message): bool => !in_array($message->transportName, $this->pausedTransports, true)));
        if ([] === $messages) {
            return null;
        }

        // A message whose last attempt was interrupted may kill the process again: the messages sent
        // before it are marked as published first, so that only it is blamed (and sent again).
        $interrupted = 1 === count($messages) && null !== $messages[0]->claimedAt;
        if ($interrupted && [] !== $this->sent) {
            $this->flush();
        }

        $claimed = array_flip($this->claim($messages));
        $claimedAt = $this->now();
        $pending = array_values(array_filter($messages, static fn (OutboxMessage $message): bool => isset($claimed[$message->id])));
        // Published, or claimed by a relay that overlaps this one (e.g. a lock store that is local to one host).
        $this->claimedElsewhere += count($messages) - count($pending);
        $unattempted = [];
        $end = null;

        try {
            while (null === $end && [] !== $pending) {
                $message = array_shift($pending);
                if (in_array($message->transportName, $this->pausedTransports, true)) {
                    $unattempted[] = $message;

                    continue;
                }

                // A claim holds until the retry time of its attempt: renew the claims of the batch
                // before they run out, and skip what another relay took over meanwhile.
                if ($this->now() - $claimedAt >= self::RENEW_SECONDS) {
                    $held = array_flip($this->renew([$message, ...$pending]));
                    $claimedAt = $this->now();
                    $kept = array_values(array_filter($pending, static fn (OutboxMessage $other): bool => isset($held[$other->id])));
                    $this->claimedElsewhere += count($pending) - count($kept) + (isset($held[$message->id]) ? 0 : 1);
                    $pending = $kept;
                    if (!isset($held[$message->id])) {
                        continue;
                    }
                }

                $started = $this->now();
                $outcome = $this->process($message, $reporter);
                if ($interrupted && [] !== $this->sent) {
                    $this->flush();
                }
                $this->count($message, $outcome, $started, $reporter);

                if (!$reporter->continueAfterMessage()) {
                    $end = self::ABORT;
                } elseif ($this->processed >= $limit || $reporter->stopRequested()) {
                    $end = self::END;
                }
            }
        } catch (\Throwable $exception) {
            // The run stops: the claims not attempted go back, if the storage still works.
            try {
                $this->release([...$unattempted, ...$pending]);
            } catch (\Throwable) {
                // They are retried as interrupted attempts; the first failure matters.
            }

            throw $exception;
        }

        $this->release([...$unattempted, ...$pending]);

        return $end;
    }

    /**
     * @param self::RELAYED|self::FAILED|self::TRANSPORT_FAILED|self::CLAIMED_ELSEWHERE $outcome
     */
    private function count(OutboxMessage $message, string $outcome, float $started, RelayReporter $reporter): void
    {
        // After every outcome, failures included: a run of failing sends must not hold back the marks.
        if ($this->now() - $this->flushedAt >= self::FLUSH_SECONDS) {
            $this->flush();
        }

        $key = $message->transportName ?? '';

        if (self::CLAIMED_ELSEWHERE === $outcome) {
            ++$this->claimedElsewhere;

            return;
        }

        ++$this->processed;

        if (self::RELAYED === $outcome) {
            ++$this->relayed;
            $this->consecutiveTransportFailures[$key] = 0;
            $this->workingTransports[$key] = true;
            unset($this->failingSince[$key]);

            return;
        }

        ++$this->failed;
        if (self::TRANSPORT_FAILED !== $outcome) {
            return;
        }

        $failures = $this->consecutiveTransportFailures[$key] = ($this->consecutiveTransportFailures[$key] ?? 0) + 1;

        // The transport is probably down: do not walk its whole backlog, but keep relaying the other transports.
        $this->failingSince[$key] ??= $started;
        $pause = isset($this->workingTransports[$key])
            ? self::MAX_CONSECUTIVE_FAILURES_OF_A_WORKING_TRANSPORT <= $failures || (self::MAX_CONSECUTIVE_TRANSPORT_FAILURES <= $failures && $this->now() - $this->failingSince[$key] >= self::MAX_FAILING_SECONDS)
            : self::MAX_CONSECUTIVE_TRANSPORT_FAILURES <= $failures;
        if ($pause) {
            $this->pausedTransports[] = $message->transportName;
            $reporter->transportPaused($message->transportName, $failures);
            $this->logger?->warning('The outbox relay paused transport {transport} for this run after {count} consecutive failures.', ['transport' => $message->transportName ?? '(routing)', 'count' => $failures]);
        }
    }

    private function now(): float
    {
        return null === $this->clock ? microtime(true) : ($this->clock)();
    }

    /**
     * Attempts a claimed message. The claim counted the attempt, so the stored attempts are one
     * more than the fetched ones.
     *
     * @return self::RELAYED|self::FAILED|self::TRANSPORT_FAILED|self::CLAIMED_ELSEWHERE
     */
    private function process(OutboxMessage $message, RelayReporter $reporter): string
    {
        $attempt = $message->attempts + 1;

        // The process died during the last attempt (fatal error, out of memory, killed) and the
        // largest budget is used up: do not try again, the message may be what kills it. (Whether
        // the earlier attempts failed on the transport is unknown, so the transport budget applies;
        // the interrupted attempt already counted.)
        if (null !== $message->claimedAt && $message->attempts >= self::TRANSPORT_ATTEMPTS_FACTOR * $this->maxAttempts) {
            $error = null === $message->lastError || '' === $message->lastError ? self::INTERRUPTED : self::INTERRUPTED.' Previous error: '.$message->lastError;

            return $this->giveUp($message, $message->attempts, $error, $reporter);
        }

        // A stored transport that does not exist (a typo, a renamed transport) fails every attempt:
        // give the message up at once, to be requeued with the right transport name.
        if (null !== $message->transportName && null !== $this->transports && !$this->transports->has($message->transportName)) {
            return $this->giveUp($message, $attempt, sprintf('The transport "%s" does not exist (is the row from another application sharing this table?). Fix the code that stores it, then run "somework:cqrs:outbox:failed --requeue --transport=<name> %s".', $message->transportName, $message->id), $reporter);
        }

        // Only rows this application signed reach the serializer (PHP's unserialize() by default).
        $unverified = $this->unverified($message);
        if (null !== $unverified) {
            return $this->giveUp($message, $attempt, $unverified, $reporter);
        }

        try {
            $this->send($message, $this->decode($message), $reporter);
        } catch (\Throwable $exception) {
            return $this->fail($message, $attempt, $exception, $reporter);
        }

        $this->sent[] = $message->id;

        return self::RELAYED;
    }

    /**
     * Why the message must not be decoded, or null when its signature is valid (or not checked).
     */
    private function unverified(OutboxMessage $message): ?string
    {
        if (null === $this->signer || $this->signer->verify($message)) {
            return null;
        }

        if (null === $message->signature) {
            if ($this->acceptUnsigned) {
                return null;
            }

            return sprintf('The message is not signed, so it was not decoded: it was stored before outbox signing was enabled, by code that bypasses the OutboxStorage service, or by someone else. Delete the row unless your application stored it; if it did, run "somework:cqrs:outbox:failed --requeue --sign %s", which checks the classes in the body before signing (or set "somework_cqrs.outbox.signing.accept_unsigned" while rows of an earlier version drain).', $message->id);
        }

        return sprintf('The signature of the message does not match, so it was not decoded: the row was changed or written by someone else (another application sharing this table?), or signed with a secret that is no longer configured (add it to "somework_cqrs.outbox.signing.previous_secrets"). Delete the row unless your application stored it; if it did, run "somework:cqrs:outbox:failed --requeue --sign %s", which checks the classes in the body before signing.', $message->id);
    }

    /**
     * @return self::FAILED|self::CLAIMED_ELSEWHERE
     */
    private function giveUp(OutboxMessage $message, int $attempts, string $error, RelayReporter $reporter): string
    {
        // May contain text read from the row (its transport name, the previous error).
        $error = self::clean($error);
        if (!$this->recordFailure($message, $attempts, $error, null)) {
            return self::CLAIMED_ELSEWHERE;
        }
        $this->reportGivenUp($message, $attempts, $error, $reporter);

        return self::FAILED;
    }

    /**
     * Records a failed attempt: the message is retried after a delay, or given up after its last attempt.
     *
     * @return self::FAILED|self::TRANSPORT_FAILED
     */
    private function fail(OutboxMessage $message, int $attempt, \Throwable $exception, RelayReporter $reporter): string
    {
        $transportFailure = self::isTransportFailure($exception);
        // A transport outage fails every message: give it about a day before giving up, instead of hours.
        $maxAttempts = $transportFailure ? self::TRANSPORT_ATTEMPTS_FACTOR * $this->maxAttempts : $this->maxAttempts;
        $retryAt = $attempt >= $maxAttempts ? null : self::inSeconds($this->delayFor($attempt));
        $error = self::describe($exception);
        $outcome = $transportFailure ? self::TRANSPORT_FAILED : self::FAILED;

        if (!$this->recordFailure($message, $attempt, $error, $retryAt)) {
            // Published or claimed by an overlapping relay in the meantime: its outcome counts.
            $reporter->claimedElsewhereAfterFailure($message, $error);
            $this->logger?->warning('Could not relay outbox message {id}, which another relay claimed in the meantime: {error}', ['id' => $message->id, 'error' => $error, 'exception' => $exception] + self::logContext($message));

            return $outcome;
        }

        if (null === $retryAt) {
            $this->reportGivenUp($message, $attempt, $error, $reporter, $exception);
        } else {
            $reporter->attemptFailed($message, $attempt, $maxAttempts, $retryAt, $error);
            $this->logger?->warning('Could not relay outbox message {id} (attempt {attempt} of {max_attempts}): {error}', ['id' => $message->id, 'attempt' => $attempt, 'max_attempts' => $maxAttempts, 'error' => $error, 'exception' => $exception] + self::logContext($message));
        }

        return $outcome;
    }

    /**
     * Claims the messages for an attempt. Until the attempt is finished, a message is due again
     * only after the retry delay of this attempt: if the process dies, the next run does not start
     * with the same message right away.
     *
     * @param non-empty-list<OutboxMessage> $messages
     *
     * @throws \RuntimeException when the storage fails, which stops the run
     *
     * @return list<string>
     */
    private function claim(array $messages): array
    {
        $retryAt = [];
        foreach ($messages as $message) {
            $retryAt[$message->attempts] ??= self::inSeconds($this->delayFor($message->attempts + 1));
        }

        try {
            return $this->outboxStorage->claim($messages, $retryAt, $this->token);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf('Could not claim %d outbox message(s): %s', count($messages), self::describe($exception)), 0, $exception);
        }
    }

    /**
     * @param non-empty-list<OutboxMessage> $messages
     *
     * @throws \RuntimeException when the storage fails, which stops the run
     *
     * @return list<string> The ids still claimed by this run
     */
    private function renew(array $messages): array
    {
        $retryAt = [];
        foreach ($messages as $message) {
            $retryAt[$message->attempts] ??= self::inSeconds($this->delayFor($message->attempts + 1));
        }

        try {
            return $this->outboxStorage->renew($messages, $retryAt, $this->token);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf('Could not renew the claims of %d outbox message(s): %s', count($messages), self::describe($exception)), 0, $exception);
        }
    }

    /**
     * @param list<OutboxMessage> $messages
     *
     * @throws \RuntimeException when the storage fails, which stops the run
     */
    private function release(array $messages): void
    {
        if ([] === $messages) {
            return;
        }

        try {
            $this->outboxStorage->release($messages, $this->token);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf('Could not release the claims of %d outbox message(s), which the next runs retry as interrupted attempts: %s', count($messages), self::describe($exception)), 0, $exception);
        }
    }

    /**
     * @throws \RuntimeException when the storage fails, which stops the run
     */
    private function recordFailure(OutboxMessage $message, int $attempts, string $error, ?DateTimeImmutable $retryAt): bool
    {
        try {
            return $this->outboxStorage->recordFailure($message->id, $this->token, $attempts, $error, $retryAt);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf('Could not record the failed attempt to relay message "%s": %s', $message->id, self::describe($exception)), 0, $exception);
        }
    }

    /**
     * Marks the messages sent since the last call as published.
     *
     * @param bool $final Whether the run ends: a deadlock or serialization failure is only retried
     *                    at the next flush during the run (the relay still holds the claims)
     *
     * @throws \RuntimeException when the storage fails, which stops the run
     */
    private function flush(bool $final = false): void
    {
        $this->flushedAt = $this->now();
        if ([] === $this->sent) {
            return;
        }

        [$ids, $this->sent] = [$this->sent, []];
        try {
            $this->outboxStorage->markPublished($ids);
        } catch (RetryableException $exception) {
            if ($final) {
                throw new \RuntimeException(sprintf('%d sent message(s) could not be marked as published, so they will be sent again (%s): %s', count($ids), implode(', ', $ids), self::describe($exception)), 0, $exception);
            }

            // Marking is idempotent: try again at the next flush.
            $this->sent = [...$ids, ...$this->sent];
            $this->logger?->warning('Could not mark {count} sent outbox message(s) as published yet, retrying at the next flush: {error}', ['count' => count($ids), 'error' => self::describe($exception)]);
        } catch (\Throwable $exception) {
            // The claims stay, so the messages are sent again later (at least once).
            throw new \RuntimeException(sprintf('%d sent message(s) could not be marked as published, so they will be sent again (%s): %s', count($ids), implode(', ', $ids), self::describe($exception)), 0, $exception);
        }
    }

    /**
     * Whether sending failed because of the transport (it cannot be reached, or it rejects the
     * message). A handler that ran inline and failed is not a transport failure, even when it
     * failed to send another message: its side effects happened.
     */
    private static function isTransportFailure(\Throwable $exception): bool
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof HandlerFailedException || $current instanceof DelayedMessageHandlingException) {
                return false;
            }

            if ($current instanceof TransportException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seconds to wait after attempt number $attempt failed: 1 minute, doubling up to 1 hour.
     */
    private function delayFor(int $attempt): int
    {
        return min(self::RETRY_DELAY * 2 ** min(max($attempt, 1) - 1, 30), self::MAX_RETRY_DELAY);
    }

    private static function inSeconds(int $seconds): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('+%d seconds', $seconds));
    }

    private function reportGivenUp(OutboxMessage $message, int $attempts, string $error, RelayReporter $reporter, ?\Throwable $exception = null): void
    {
        $reporter->gaveUp($message, $attempts, $error);
        $this->logger?->error('Gave up on outbox message {id} after {attempts} attempt(s): {error}', ['id' => $message->id, 'attempts' => $attempts, 'error' => $error] + self::logContext($message) + (null === $exception ? [] : ['exception' => $exception]));
    }

    public static function describe(\Throwable $exception): string
    {
        return self::clean('' === $exception->getMessage() ? $exception::class : sprintf('%s: %s', $exception::class, $exception->getMessage()));
    }

    /**
     * The transport and the "type" header of a row (read as JSON, not decoded), to group failures by.
     *
     * @return array{transport: string, type: string}
     */
    private static function logContext(OutboxMessage $message): array
    {
        try {
            $headers = json_decode($message->headers, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $headers = null;
        }

        return [
            'transport' => self::clean($message->transportName ?? '(routing)'),
            'type' => self::clean(is_array($headers) && is_string($headers['type'] ?? null) ? $headers['type'] : '?'),
        ];
    }

    /**
     * Stored in a text column and shown in terminals: valid UTF-8, bounded, and without control
     * characters (escape sequences in a message would reach the terminal of the operator).
     */
    private static function clean(string $text): string
    {
        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F\x{80}-\x{9F}]+/u', ' ', mb_scrub($text, 'UTF-8')), 0, self::MAX_ERROR_LENGTH, 'UTF-8');
    }

    private function decode(OutboxMessage $message): Envelope
    {
        $envelope = $this->serializer->decode([
            'body' => $message->body,
            'headers' => json_decode($message->headers, true, 512, JSON_THROW_ON_ERROR),
        ]);

        // Since Symfony 8, serializers report decoding failures inside the envelope instead of throwing.
        $decoded = $envelope->getMessage();
        if ($decoded instanceof MessageDecodingFailedException) {
            throw $decoded;
        }
        if (null !== $envelope->last(MessageDecodingFailedStamp::class)) {
            throw new MessageDecodingFailedException(sprintf('The class of the message (%s) cannot be loaded.', $decoded::class));
        }

        // Stamps that only describe a dispatch in progress (e.g. ReceivedStamp, which would make the
        // relay handle the message itself instead of sending it) are not taken from a row: the
        // serializers drop them when they encode an envelope, only a forged row can hold them.
        $envelope = $envelope->withoutStampsOfType(NonSendableStampInterface::class)->withoutAll(HandledStamp::class);

        if (null !== $message->transportName) {
            $envelope = $envelope->with(new TransportNamesStamp([$message->transportName]));
        }

        return $envelope;
    }

    private function send(OutboxMessage $message, Envelope $envelope, RelayReporter $reporter): void
    {
        // The bus of the message type adds the BusNameStamp workers use to pick the bus (a stored one is kept).
        $envelope = $this->busFor($envelope->getMessage())->dispatch($envelope);

        if (null !== $envelope->last(SentStamp::class)) {
            return;
        }

        // Messenger's deduplication (symfony/messenger 7.3+) dropped a retry. The lock is most likely
        // held by an earlier attempt of this message that did not send it (the process died, or the
        // send failed without releasing the lock): marking it published would lose it. Retry it
        // after the backoff, by when the lock (300 seconds by default) has usually expired.
        $deduplicate = $envelope->last(DeduplicateStamp::class);
        if ($deduplicate instanceof DeduplicateStamp && $message->attempts > 0 && null === $envelope->last(HandledStamp::class)) {
            throw new \RuntimeException(sprintf('Messenger\'s deduplication dropped this retry: the lock "%s" is still held, probably by an earlier attempt of this message. It is retried when the lock has expired.', (string) $deduplicate->getKey()));
        }

        $warning = null !== $envelope->last(HandledStamp::class)
            ? 'Message "%s" (%s) was not sent to any transport and was handled synchronously. Set a transport name or route the message to a transport.'
            : 'Message "%s" (%s) was neither sent to a transport nor handled (e.g. Messenger\'s deduplication dropped it as a duplicate of another message, or it is an event without handlers); it is marked as published.';

        $reporter->notSent(sprintf($warning, $message->id, $envelope->getMessage()::class));
        $this->logger?->warning(sprintf($warning, '{id}', '{class}'), ['id' => $message->id, 'class' => $envelope->getMessage()::class]);
    }

    private function busFor(object $message): MessageBusInterface
    {
        $type = match (true) {
            $message instanceof CommandMessage => 'command',
            $message instanceof Query => 'query',
            $message instanceof Event => 'event',
            default => null,
        };

        if (null === $type || null === $this->buses || !$this->buses->has($type)) {
            return $this->messageBus;
        }

        $bus = $this->buses->get($type);

        return $bus instanceof MessageBusInterface ? $bus : $this->messageBus;
    }
}
