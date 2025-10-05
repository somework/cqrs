<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

final class ImportLegacyDataCommand implements BulkImportCommand
{
    public function __construct(public readonly string $source)
    {
    }
}
