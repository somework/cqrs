<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\StampDecider;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Support\CausationIdContext;
use SomeWork\CqrsBundle\Support\CausationIdStampDecider;

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
        $this->context->push(self::parent('parent-corr'));
        $message = new class implements Command {};
        $stamps = [];

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, $stamps);

        self::assertSame([], $result);
    }

    public function test_replaces_metadata_stamp_with_causation_id_from_context(): void
    {
        $this->context->push(self::parent('parent-corr'));
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
            'SomeWork\CqrsBundle\Contract\MessageTypeAwareStampDecider',
            $interfaces,
        );
    }

    public function test_preserves_other_stamps_when_replacing_metadata_stamp(): void
    {
        $this->context->push(self::parent('parent-corr'));
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
        $this->context->push(self::parent('parent-corr'));
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
        $this->context->push(self::parent('parent-corr'));
        $message = new class implements Command {};

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, []);

        self::assertSame([], $result);
    }

    public function test_keeps_an_explicit_causation_id(): void
    {
        $this->context->push(self::parent('new-parent'));
        $message = new class implements Command {};
        $metadataStamp = new MessageMetadataStamp('child-corr', [], 'explicit-parent');

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, [$metadataStamp]);

        self::assertSame([$metadataStamp], $result);
    }

    public function test_enriches_the_last_metadata_stamp(): void
    {
        $this->context->push(self::parent('parent-corr'));
        $message = new class implements Command {};
        $first = new MessageMetadataStamp('first');
        $last = new MessageMetadataStamp('last');

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, [$first, $last]);

        self::assertCount(2, $result);
        self::assertSame($first, $result[0]);
        self::assertInstanceOf(MessageMetadataStamp::class, $result[1]);
        self::assertSame('last', $result[1]->getCorrelationId());
        self::assertSame('parent-corr', $result[1]->getCausationId());
    }

    public function test_a_forwarded_stamp_of_the_handled_message_gets_its_own_message_id(): void
    {
        $handled = new MessageMetadataStamp('flow', [], 'grandparent', 'handled');
        $this->context->push($handled);

        $result = $this->decider->decide(new class implements Command {}, DispatchMode::DEFAULT, [$handled->withExtra('tenant', 'x')]);

        self::assertInstanceOf(MessageMetadataStamp::class, $result[0]);
        self::assertNotSame('handled', $result[0]->getMessageId());
        self::assertSame('handled', $result[0]->getCausationId());
        self::assertSame('flow', $result[0]->getCorrelationId());
        self::assertSame(['tenant' => 'x'], $result[0]->getExtras());
    }

    public function test_a_message_without_metadata_has_no_parent_for_its_children(): void
    {
        $this->context->push(self::parent('outer'));
        $this->context->push(null);

        $stamp = new MessageMetadataStamp('child');

        self::assertSame([$stamp], $this->decider->decide(new class implements Command {}, DispatchMode::DEFAULT, [$stamp]));
    }

    private static function parent(string $messageId): MessageMetadataStamp
    {
        return new MessageMetadataStamp('flow', [], null, $messageId);
    }
}
