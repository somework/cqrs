<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Support\CausationIdContext;
use SomeWork\CqrsBundle\Support\CausationIdStampDecider;
use SomeWork\CqrsBundle\Support\StampDecider;

#[CoversClass(CausationIdStampDecider::class)]
final class CausationIdStampDeciderTest extends TestCase
{
    private CausationIdContext $context;

    private CausationIdStampDecider $decider;

    protected function setUp(): void
    {
        $this->context = new CausationIdContext();
        $this->decider = new CausationIdStampDecider($this->context);
    }

    public function test_implements_stamp_decider_interface(): void
    {
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(StampDecider::class, $this->decider);
    }

    public function test_returns_stamps_unchanged_when_context_has_no_current(): void
    {
        $message = new class implements Command {};
        $metadataStamp = new MessageMetadataStamp('corr-1');
        $stamps = [$metadataStamp];

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, $stamps);

        self::assertSame($stamps, $result);
    }

    public function test_returns_stamps_unchanged_when_no_metadata_stamp_present(): void
    {
        $this->context->push('parent-corr');
        $message = new class implements Command {};
        $stamps = [];

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, $stamps);

        self::assertSame([], $result);
    }

    public function test_replaces_metadata_stamp_with_causation_id_from_context(): void
    {
        $this->context->push('parent-corr');
        $message = new class implements Command {};
        $metadataStamp = new MessageMetadataStamp('child-corr', ['key' => 'val']);
        $stamps = [$metadataStamp];

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, $stamps);

        self::assertCount(1, $result);
        self::assertInstanceOf(MessageMetadataStamp::class, $result[0]);
        self::assertNotSame($metadataStamp, $result[0]);
        self::assertSame('child-corr', $result[0]->getCorrelationId());
        self::assertSame('parent-corr', $result[0]->getCausationId());
        self::assertSame(['key' => 'val'], $result[0]->getExtras());
    }

    public function test_does_not_implement_message_type_aware_stamp_decider(): void
    {
        $reflection = new \ReflectionClass(CausationIdStampDecider::class);
        $interfaces = $reflection->getInterfaceNames();

        self::assertNotContains(
            'SomeWork\CqrsBundle\Support\MessageTypeAwareStampDecider',
            $interfaces,
        );
    }

    public function test_preserves_other_stamps_when_replacing_metadata_stamp(): void
    {
        $this->context->push('parent-corr');
        $message = new class implements Command {};
        $otherStamp = new \Symfony\Component\Messenger\Stamp\DelayStamp(1000);
        $metadataStamp = new MessageMetadataStamp('child-corr');
        $stamps = [$otherStamp, $metadataStamp];

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, $stamps);

        self::assertCount(2, $result);
        self::assertInstanceOf(\Symfony\Component\Messenger\Stamp\DelayStamp::class, $result[0]);
        self::assertInstanceOf(MessageMetadataStamp::class, $result[1]);
        self::assertSame('parent-corr', $result[1]->getCausationId());
    }

    public function test_finds_metadata_stamp_in_middle_of_stamps_array(): void
    {
        $this->context->push('parent-corr');
        $message = new class implements Command {};
        $delay = new \Symfony\Component\Messenger\Stamp\DelayStamp(500);
        $metadata = new MessageMetadataStamp('child-corr', ['key' => 'val']);
        $busName = new \Symfony\Component\Messenger\Stamp\BusNameStamp('command.bus');
        $stamps = [$delay, $metadata, $busName];

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, $stamps);

        self::assertCount(3, $result);
        // Find the metadata stamp in result (moved to end after replacement)
        $metadataResults = array_filter($result, static fn ($s) => $s instanceof MessageMetadataStamp);
        self::assertCount(1, $metadataResults);
        $metadataResult = reset($metadataResults);
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(MessageMetadataStamp::class, $metadataResult);
        self::assertSame('parent-corr', $metadataResult->getCausationId());
        self::assertSame('child-corr', $metadataResult->getCorrelationId());
        self::assertSame(['key' => 'val'], $metadataResult->getExtras());
    }

    public function test_returns_empty_array_when_stamps_empty_and_context_has_value(): void
    {
        $this->context->push('parent-corr');
        $message = new class implements Command {};

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, []);

        self::assertSame([], $result);
    }

    public function test_replaces_existing_causation_id_with_current_context(): void
    {
        $this->context->push('new-parent');
        $message = new class implements Command {};
        $metadataStamp = new MessageMetadataStamp('child-corr', [], 'old-parent');
        $stamps = [$metadataStamp];

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, $stamps);

        self::assertInstanceOf(MessageMetadataStamp::class, $result[0]);
        self::assertSame('new-parent', $result[0]->getCausationId());
    }
}
