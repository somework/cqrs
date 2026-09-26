<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxSchema;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\Relay\OutboxRelay;
use SomeWork\CqrsBundle\Outbox\Relay\RelayUnitOfWork;
use SomeWork\CqrsBundle\Outbox\Signing\OutboxSigner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Contracts\Service\ResetInterface;

use function class_exists;
use function defined;
use function filter_var;
use function implode;
use function microtime;
use function min;
use function register_shutdown_function;
use function sprintf;
use function usleep;

use const FILTER_VALIDATE_BOOL;
use const FILTER_VALIDATE_FLOAT;
use const FILTER_VALIDATE_INT;
use const SIGINT;
use const SIGTERM;

/**
 * Runs OutboxRelay from the console: validates the limit, holds the relay lock, stops after the
 * current message on SIGTERM or SIGINT, and reports the outcome. With --watch it keeps relaying
 * until it is stopped, like messenger:consume.
 *
 * @internal
 */
#[AsCommand(
    name: 'somework:cqrs:outbox:relay',
    description: 'Relay unpublished outbox messages to their transports.',
)]
final class OutboxRelayCommand extends Command implements SignalableCommandInterface
{
    use LockableTrait;

    /** Seconds between two extensions of the relay lock. */
    private const LOCK_REFRESH_SECONDS = 10;

    /**
     * TTL of the relay lock. A relay killed without cleanup (SIGKILL, OOM kill) keeps the next
     * runs out until it expires; a running relay extends it every LOCK_REFRESH_SECONDS.
     */
    public const LOCK_TTL_SECONDS = 60.0;

    /** Exit code of a run that --wait-for-lock gave up on: another relay kept the lock. */
    public const LOCK_TAKEN = 3;

    /** When the relay lock was last extended. */
    private ?float $lockRefreshedAt = null;

    /** The signal that asked the run to stop after the current message. */
    private ?int $stopSignal = null;

    private bool $releasesLockOnShutdown = false;

    private bool $resetServices = true;

    private readonly OutboxRelay $relay;

    /**
     * @param ContainerInterface|null      $buses          Buses keyed by message type ("command", "query", "event"); other messages use $messageBus
     * @param int                          $maxAttempts    Attempts after which a failing message is given up (three times as many when its transport fails)
     * @param (\Closure(): float)|null     $clock          Seconds since the epoch, microtime(true) by default (for tests)
     * @param OutboxSchema|null            $table          The storage behind a decorated $outboxStorage, for the report of pending schema changes
     * @param ContainerInterface|null      $transports     Messenger's transports by name; a message stored for another transport is given up at once
     * @param OutboxSigner|null            $signer         Verifies every message before it is decoded (outbox.signing)
     * @param bool|string                  $acceptUnsigned Relay messages without a signature (outbox.signing.accept_unsigned, possibly from an environment variable)
     * @param ResetInterface|null          $resetter       Resets the application's services after each message the relay handled itself, as Messenger's workers do between messages
     * @param (\Closure(float): void)|null $sleep          Waits the given seconds, usleep() by default (for tests)
     */
    public function __construct(
        private readonly OutboxStorage $outboxStorage,
        SerializerInterface $serializer,
        MessageBusInterface $messageBus,
        ?LockFactory $lockFactory = null,
        ?ContainerInterface $buses = null,
        private readonly string $lockName = 'somework:cqrs:outbox:relay',
        int $maxAttempts = 10,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?\Closure $clock = null,
        private readonly ?OutboxSchema $table = null,
        ?ContainerInterface $transports = null,
        ?OutboxSigner $signer = null,
        bool|string $acceptUnsigned = false,
        ?RelayUnitOfWork $unitOfWork = null,
        private readonly ?ResetInterface $resetter = null,
        private readonly ?\Closure $sleep = null,
    ) {
        $this->relay = new OutboxRelay($outboxStorage, $serializer, $messageBus, $buses, $maxAttempts, $logger, $clock, $transports, $signer, true === filter_var($acceptUnsigned, FILTER_VALIDATE_BOOL), $unitOfWork);

        parent::__construct();

        $this->lockFactory = $lockFactory;
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return defined('SIGTERM') && SignalRegistry::isSupported() ? [SIGTERM, SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        // A second signal stops right away, like a process without a handler.
        if (null !== $this->stopSignal) {
            return 128 + $signal;
        }

        // Finish the current message instead of leaving it half done, then stop (see OutboxRelay::run()).
        $this->stopSignal = $signal;

        return false;
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of messages to process in this run (with --watch: in each run)', '100');
        $this->addOption('watch', 'w', InputOption::VALUE_NONE, 'Keep relaying until SIGTERM, SIGINT or --time-limit: runs again as soon as messages are due');
        $this->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to wait before looking again when no message is due (with --watch)', '1');
        $this->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop watching after this many seconds (with --watch)');
        $this->addOption('wait-for-lock', null, InputOption::VALUE_REQUIRED, 'Seconds to wait for another relay to release the lock, then exit with 3 (--watch waits as long as it runs)', '0');
        $this->addOption('no-reset', null, InputOption::VALUE_NONE, 'Do not reset the application\'s services after the messages the relay handles itself (no transport, sync://)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);

        if (false === $limit || $limit < 1) {
            $io->error('Limit must be a positive integer.');

            return self::INVALID;
        }

        $watch = (bool) $input->getOption('watch');
        $sleep = filter_var($input->getOption('sleep'), FILTER_VALIDATE_FLOAT);
        $timeLimit = null === $input->getOption('time-limit') ? null : filter_var($input->getOption('time-limit'), FILTER_VALIDATE_INT);
        $waitForLock = filter_var($input->getOption('wait-for-lock'), FILTER_VALIDATE_FLOAT);
        if (false === $sleep || $sleep <= 0 || false === $timeLimit || (null !== $timeLimit && $timeLimit < 1) || false === $waitForLock || $waitForLock < 0) {
            $io->error('--sleep must be a positive number of seconds, --time-limit a positive integer and --wait-for-lock a number of seconds.');

            return self::INVALID;
        }
        if (!$watch && null !== $timeLimit) {
            $io->error('--time-limit only applies with --watch.');

            return self::INVALID;
        }

        $this->resetServices = true !== $input->getOption('no-reset');

        // Overlapping runs (cron) would publish the same rows twice.
        try {
            $acquired = $this->waitForLock($io, $watch ? null : $waitForLock, $sleep, $timeLimit);
        } catch (LockException $exception) {
            return $this->stop($io, 'the relay lock could not be acquired', $exception);
        }
        if (true !== $acquired) {
            $this->stopSignal = null;

            return $acquired;
        }

        $this->releaseLockOnShutdown();

        try {
            return $watch ? $this->watch($io, $limit, $sleep, $timeLimit) : $this->relayMessages($io, $limit);
        } catch (\Throwable $exception) {
            // e.g. the database is down: exit with 1 and say why, instead of the driver's error code.
            return $this->stop($io, 'the outbox storage failed', $exception);
        } finally {
            $this->stopSignal = null;

            try {
                $this->release();
            } catch (LockReleasingException $exception) {
                // The lock expires on its own; the outcome of the run matters more.
                $io->warning(sprintf('Could not release the relay lock: %s', $exception->getMessage()));
            }
        }
    }

    private function relayMessages(SymfonyStyle $io, int $limit): int
    {
        $result = $this->relay->run($limit, new ConsoleRelayReporter(
            $io,
            fn (): bool => $this->keepLock($io),
            fn (): bool => null !== $this->stopSignal,
        ), $this->resetServices ? $this->resetter : null);
        if ($result->aborted) {
            return self::FAILURE;
        }

        // The storage behind a decorated one still tells what its schema needs.
        $table = $this->table ?? ($this->outboxStorage instanceof OutboxSchema ? $this->outboxStorage : null);
        if (null !== $table) {
            $this->reportPendingChanges($table, $io);
        }

        if ($result->claimedElsewhere > 0) {
            $io->note(sprintf('Skipped %d message(s) that another relay claimed first.', $result->claimedElsewhere));
        }

        if ($result->relayed > 0) {
            $io->success(sprintf('Relayed %d message(s).', $result->relayed));
        }

        if (null !== $this->stopSignal) {
            $io->warning(sprintf('Stopped by signal %d after %d message(s); the remaining messages wait for the next run.', $this->stopSignal, $result->processed));
            $this->logger?->warning('The outbox relay stopped on signal {signal} after {count} message(s).', ['signal' => $this->stopSignal, 'count' => $result->processed]);

            return self::FAILURE;
        }

        if (0 === $result->processed) {
            if (0 === $result->claimedElsewhere) {
                $io->info('No outbox messages are due.');
            }

            return self::SUCCESS;
        }

        return 0 === $result->failed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Relays in runs of up to $limit messages until a signal or the time limit stops it, waiting
     * $sleep seconds when no message was due. Failed messages wait for their retry time; the
     * storage failing, or the lock being lost, stops the command.
     */
    private function watch(SymfonyStyle $io, int $limit, float $sleep, ?int $timeLimit): int
    {
        $started = $this->now();
        $io->note(sprintf('Watching the outbox%s. Stop with Ctrl+C or SIGTERM.', null === $timeLimit ? '' : sprintf(' for %d seconds', $timeLimit)));
        $reporter = new ConsoleRelayReporter(
            $io,
            fn (): bool => $this->keepLock($io),
            fn (): bool => null !== $this->stopSignal,
        );
        $expired = static fn (float $now): bool => null !== $timeLimit && $now - $started >= $timeLimit;

        for ($run = 0;; ++$run) {
            $result = $this->relay->run($limit, $reporter, $this->resetServices ? $this->resetter : null);
            if ($result->aborted) {
                return self::FAILURE;
            }
            if (0 === $run) {
                $table = $this->table ?? ($this->outboxStorage instanceof OutboxSchema ? $this->outboxStorage : null);
                if (null !== $table) {
                    $this->reportPendingChanges($table, $io);
                }
            }
            if ($result->relayed > 0) {
                $io->text(sprintf('Relayed %d message(s).', $result->relayed));
            }
            if (null !== $this->stopSignal) {
                $io->success(sprintf('Stopped by signal %d.', $this->stopSignal));

                return self::SUCCESS;
            }
            if ($expired($this->now())) {
                $io->success('Stopped: the time limit was reached.');

                return self::SUCCESS;
            }

            // A full run leaves more due messages: go on at once.
            if ($result->processed < $limit) {
                $until = $this->now() + $sleep;
                while (null === $this->stopSignal && ($now = $this->now()) < $until && !$expired($now)) {
                    $this->pause(min(0.1, $until - $now));
                    if (!$this->keepLock($io)) {
                        return self::FAILURE;
                    }
                }
            }
        }
    }

    /**
     * Takes the relay lock. When another relay holds it, a single run waits up to $seconds for it
     * (0: not at all), and --watch ($seconds null) waits as long as it runs: a second watcher is a
     * standby that takes over when the first one stops.
     *
     * @return true|int True with the lock, else the exit code
     */
    private function waitForLock(SymfonyStyle $io, ?float $seconds, float $sleep, ?int $timeLimit): true|int
    {
        if (!class_exists(LockFactory::class) || $this->acquireLock()) {
            return true;
        }

        if (0.0 === $seconds) {
            $io->note('Another outbox relay is already running.');

            return self::SUCCESS;
        }

        $started = $this->now();
        $until = null === $seconds ? null : $started + $seconds;
        $retryAfter = null === $seconds ? $sleep : min($sleep, 0.5);
        $io->note('Waiting for the relay lock held by another relay.');
        $this->logger?->info('The outbox relay is waiting for the relay lock held by another relay.');
        $retryAt = $started + $retryAfter;
        while (null === $this->stopSignal) {
            $now = $this->now();
            if (null !== $until && $now >= $until) {
                $io->note('Another outbox relay kept the lock.');

                return self::LOCK_TAKEN;
            }
            if (null !== $timeLimit && $now - $started >= $timeLimit) {
                $io->success('Stopped: the time limit was reached.');

                return self::SUCCESS;
            }
            if ($now >= $retryAt) {
                if ($this->acquireLock()) {
                    return true;
                }
                $retryAt = $now + $retryAfter;
                continue;
            }
            $this->pause(min(0.1, $retryAt - $now, null === $until ? 0.1 : $until - $now));
        }

        $io->success(sprintf('Stopped by signal %d.', $this->stopSignal));

        return self::SUCCESS;
    }

    private function pause(float $seconds): void
    {
        if (null !== $this->sleep) {
            ($this->sleep)($seconds);

            return;
        }

        usleep((int) ($seconds * 1_000_000));
    }

    /**
     * The automatic setup leaves indexes to the setup command: without them, every fetch reads the
     * whole table.
     */
    private function reportPendingChanges(OutboxSchema $storage, SymfonyStyle $io): void
    {
        try {
            $changes = $storage->pendingChanges();
        } catch (\Throwable) {
            return;
        }

        if ([] !== $changes) {
            $io->warning(sprintf('The outbox table needs "bin/console somework:cqrs:outbox:setup": %s.', implode('; ', $changes)));
            $this->logger?->warning('The outbox table needs "bin/console somework:cqrs:outbox:setup": {changes}.', ['changes' => implode('; ', $changes)]);
        }
    }

    private function now(): float
    {
        return null === $this->clock ? microtime(true) : ($this->clock)();
    }

    private function stop(SymfonyStyle $io, string $reason, \Throwable $exception): int
    {
        $io->error(sprintf('Stopping: %s (%s).', $reason, OutboxRelay::describe($exception)));
        $this->logger?->error('The outbox relay stopped: {reason}.', ['reason' => $reason, 'exception' => $exception]);

        return self::FAILURE;
    }

    /**
     * Extends the relay lock so it cannot expire during a long run and let a second relay in:
     * every 10 seconds, not after every message, as that costs a round trip with a lock store on
     * the network (Redis, a database).
     */
    private function keepLock(SymfonyStyle $io): bool
    {
        if (null === $this->lock) {
            return true;
        }

        $now = $this->now();
        if (null !== $this->lockRefreshedAt && $now - $this->lockRefreshedAt < self::LOCK_REFRESH_SECONDS) {
            return true;
        }
        $this->lockRefreshedAt = $now;

        try {
            $this->lock->refresh();
        } catch (LockException $exception) {
            $this->stop($io, 'the relay lock was lost, another relay may be running', $exception);

            return false;
        }

        return true;
    }

    /**
     * A PHP fatal error (e.g. running out of memory) skips "finally" blocks and destructors, so a
     * lock store with a TTL would keep the next runs out until the lock expires.
     */
    private function acquireLock(): bool
    {
        // Without the application's lock factory, LockableTrait creates a local store.
        if (null === $this->lockFactory) {
            return $this->lock($this->lockName);
        }

        $lock = $this->lockFactory->createLock($this->lockName, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            return false;
        }
        $this->lock = $lock;

        return true;
    }

    private function releaseLockOnShutdown(): void
    {
        if ($this->releasesLockOnShutdown) {
            return;
        }

        $this->releasesLockOnShutdown = true;

        register_shutdown_function(function (): void {
            try {
                $this->release();
            } catch (\Throwable) {
                // The lock expires on its own.
            }
        });
    }
}
