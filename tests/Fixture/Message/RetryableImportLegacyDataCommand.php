<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

final class RetryableImportLegacyDataCommand implements BulkImportCommand, RetryAwareMessage
{
    public function __construct(public readonly string $source)
    {
    }
}
