<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * A message a fake bus received, with the dispatch mode and the stamps it was given.
 *
 * @template-covariant TMessage of object
 *
 * @api
 */
final class RecordedDispatch
{
    /**
     * @param TMessage             $message
     * @param DispatchMode|null    $mode    Null for queries, which have no dispatch mode
     * @param list<StampInterface> $stamps
     */
    public function __construct(
        public readonly object $message,
        public readonly ?DispatchMode $mode,
        public readonly array $stamps,
    ) {
    }
}
