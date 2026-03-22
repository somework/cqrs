<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use SomeWork\CqrsBundle\Health\CheckResult;
use SomeWork\CqrsBundle\Health\CheckSeverity;
use SomeWork\CqrsBundle\Health\HealthChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

use function array_map;
use function sprintf;
use function strtoupper;

/** @internal */
#[AsCommand(
    name: 'somework:cqrs:health',
    description: 'Verify CQRS infrastructure health (handler resolvability, transport validity).',
)]
final class HealthCheckCommand extends Command
{
    /** @param iterable<HealthChecker> $checkers */
    public function __construct(
        #[AutowireIterator('somework_cqrs.health_checker')]
        private readonly iterable $checkers,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $results = [];

        foreach ($this->checkers as $checker) {
            try {
                foreach ($checker->check() as $result) {
                    $results[] = $result;
                }
            } catch (\Throwable $e) {
                $results[] = new CheckResult(
                    CheckSeverity::CRITICAL,
                    'health',
                    sprintf('Checker "%s" threw an exception: %s', $checker::class, $e->getMessage()),
                );
            }
        }

        if ([] === $results) {
            $io->success('All checks passed — no issues found.');

            return self::SUCCESS;
        }

        $rows = array_map(
            static fn (CheckResult $r): array => [
                strtoupper($r->severity->name),
                $r->category,
                $r->message,
            ],
            $results,
        );
        $io->table(['Severity', 'Category', 'Message'], $rows);

        $worstValue = 0;
        foreach ($results as $result) {
            if ($result->severity->value > $worstValue) {
                $worstValue = $result->severity->value;
            }
        }

        if (0 === $worstValue) {
            $io->success('All checks passed — no issues found.');
        } elseif (1 === $worstValue) {
            $io->warning('Health check completed with warnings.');
        } else {
            $io->error('Health check found critical issues.');
        }

        return $worstValue;
    }
}
