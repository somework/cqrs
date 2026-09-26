<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox\Signing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\Signing\OutboxSigner;
use SomeWork\CqrsBundle\Outbox\Signing\SigningOutboxStorage;

/**
 * Only store() is decorated: every other call reaches the inner storage unchanged, and its result
 * comes back unchanged.
 */
#[CoversClass(SigningOutboxStorage::class)]
final class SigningOutboxStorageTest extends TestCase
{
    private OutboxStorage&MockObject $inner;

    private SigningOutboxStorage $storage;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(OutboxStorage::class);
        $this->storage = new SigningOutboxStorage($this->inner, new OutboxSigner('secret'));
    }

    public function test_fetch_unpublished_delegates(): void
    {
        $fetched = [self::message('0199a000-0000-7000-8000-000000000001', 'v1:signature')];
        $this->inner->expects(self::once())->method('fetchUnpublished')->with(7, ['paused'])->willReturn($fetched);

        self::assertSame($fetched, $this->storage->fetchUnpublished(7, ['paused']));
    }

    public function test_claim_delegates(): void
    {
        $messages = [self::message('0199a000-0000-7000-8000-000000000001'), self::message('0199a000-0000-7000-8000-000000000002')];
        $retryAt = [0 => new DateTimeImmutable('+1 minute')];
        $this->inner->expects(self::once())->method('claim')->with(self::identicalTo($messages), self::identicalTo($retryAt), 'token')->willReturn(['0199a000-0000-7000-8000-000000000002']);

        self::assertSame(['0199a000-0000-7000-8000-000000000002'], $this->storage->claim($messages, $retryAt, 'token'));
    }

    public function test_renew_delegates(): void
    {
        $messages = [self::message('0199a000-0000-7000-8000-000000000001')];
        $retryAt = [1 => new DateTimeImmutable('+2 minutes')];
        $this->inner->expects(self::once())->method('renew')->with(self::identicalTo($messages), self::identicalTo($retryAt), 'token')->willReturn([]);

        self::assertSame([], $this->storage->renew($messages, $retryAt, 'token'));
    }

    public function test_release_delegates(): void
    {
        $messages = [self::message('0199a000-0000-7000-8000-000000000001')];
        $this->inner->expects(self::once())->method('release')->with(self::identicalTo($messages), 'token');

        $this->storage->release($messages, 'token');
    }

    public function test_mark_published_delegates(): void
    {
        $ids = ['0199a000-0000-7000-8000-000000000001', '0199a000-0000-7000-8000-000000000002'];
        $this->inner->expects(self::once())->method('markPublished')->with($ids);

        $this->storage->markPublished($ids);
    }

    /**
     * @return iterable<string, array{bool, DateTimeImmutable|null}>
     */
    public static function recordFailureResults(): iterable
    {
        yield 'recorded, retried later' => [true, new DateTimeImmutable('+5 minutes')];
        yield 'claimed elsewhere, given up' => [false, null];
    }

    #[DataProvider('recordFailureResults')]
    public function test_record_failure_delegates(bool $recorded, ?DateTimeImmutable $retryAt): void
    {
        $this->inner->expects(self::once())->method('recordFailure')->with('0199a000-0000-7000-8000-000000000001', 'token', 3, 'Transport down.', self::identicalTo($retryAt))->willReturn($recorded);

        self::assertSame($recorded, $this->storage->recordFailure('0199a000-0000-7000-8000-000000000001', 'token', 3, 'Transport down.', $retryAt));
    }

    public function test_purge_published_delegates(): void
    {
        $before = new DateTimeImmutable('-7 days');
        $this->inner->expects(self::once())->method('purgePublished')->with(self::identicalTo($before))->willReturn(42);

        self::assertSame(42, $this->storage->purgePublished($before));
    }

    private static function message(string $id, ?string $signature = null): OutboxMessage
    {
        return new OutboxMessage($id, 'body', '{}', new DateTimeImmutable('2026-01-01 10:00:00'), 'async', signature: $signature);
    }
}
