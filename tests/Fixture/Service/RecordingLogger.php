<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Service;

use Psr\Log\AbstractLogger;
use Stringable;

use function str_contains;

/**
 * PSR-3 logger keeping every record for assertions.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    public function hasRecordContaining(string $level, string $needle): bool
    {
        foreach ($this->records as $record) {
            if ($level === $record['level'] && str_contains($record['message'], $needle)) {
                return true;
            }
        }

        return false;
    }
}
