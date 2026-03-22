<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function count;

/**
 * Aggregates registered stamp deciders.
 *
 * @internal
 */
final class StampsDecider implements StampDecider
{
    /**
     * @var list<array{decider: StampDecider, messageTypes: list<class-string>}>
     */
    private array $entries = [];

    /**
     * @var array<class-string, list<StampDecider>>
     */
    private array $pipelines = [];

    /**
     * @param iterable<StampDecider> $deciders
     */
    public function __construct(
        iterable $deciders = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
        foreach ($deciders as $decider) {
            $messageTypes = [];

            if ($decider instanceof MessageTypeAwareStampDecider) {
                $messageTypes = $decider->messageTypes();
            }

            $this->entries[] = [
                'decider' => $decider,
                'messageTypes' => $messageTypes,
            ];
        }
    }

    public static function withDefaultAsyncDeferral(): self
    {
        return new self([new DispatchAfterCurrentBusStampDecider(DispatchAfterCurrentBusDecider::defaults())]);
    }

    public static function withDefaultCommandDecorators(
        RetryPolicyResolver $retryPolicies,
        MessageSerializerResolver $serializers,
        MessageMetadataProviderResolver $metadata,
        ?DispatchAfterCurrentBusDecider $dispatchAfter = null,
        ?MessageTransportResolver $transports = null,
        ?MessageTransportResolver $asyncTransports = null,
    ): self {
        return self::withDefaultsFor(
            Command::class,
            $retryPolicies,
            $serializers,
            $metadata,
            $dispatchAfter,
            $transports,
            $asyncTransports,
        );
    }

    public static function withDefaultEventDecorators(
        RetryPolicyResolver $retryPolicies,
        MessageSerializerResolver $serializers,
        MessageMetadataProviderResolver $metadata,
        ?DispatchAfterCurrentBusDecider $dispatchAfter = null,
        ?MessageTransportResolver $transports = null,
        ?MessageTransportResolver $asyncTransports = null,
    ): self {
        return self::withDefaultsFor(
            Event::class,
            $retryPolicies,
            $serializers,
            $metadata,
            $dispatchAfter,
            $transports,
            $asyncTransports,
        );
    }

    public static function withoutDecorators(): self
    {
        return new self([]);
    }

    /**
     * @param array<int, StampInterface> $stamps
     *
     * @return array<int, StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        foreach ($this->pipelineFor($message) as $decider) {
            $stampCountBefore = count($stamps);
            $stamps = $decider->decide($message, $mode, $stamps);

            $this->logger?->debug('Stamp decider processed', [
                'message' => $message::class,
                'decider' => $decider::class,
                'stamps_before' => $stampCountBefore,
                'stamps_after' => count($stamps),
            ]);
        }

        return array_values($stamps);
    }

    /**
     * @return list<StampDecider>
     */
    private function pipelineFor(object $message): array
    {
        $class = $message::class;

        if (!isset($this->pipelines[$class])) {
            $pipeline = [];

            foreach ($this->entries as $entry) {
                if ([] === $entry['messageTypes']) {
                    $pipeline[] = $entry['decider'];
                    continue;
                }

                foreach ($entry['messageTypes'] as $messageType) {
                    if ($message instanceof $messageType) {
                        $pipeline[] = $entry['decider'];
                        break;
                    }
                }
            }

            $this->pipelines[$class] = $pipeline;
        }

        return $this->pipelines[$class];
    }

    /**
     * @param class-string          $messageType
     * @param array<string, string> $transportStampTypes
     */
    public static function withDefaultsFor(
        string $messageType,
        RetryPolicyResolver $retryPolicies,
        MessageSerializerResolver $serializers,
        MessageMetadataProviderResolver $metadata,
        ?DispatchAfterCurrentBusDecider $dispatchAfter = null,
        ?MessageTransportResolver $transports = null,
        ?MessageTransportResolver $asyncTransports = null,
        ?MessageTransportStampFactory $transportStampFactory = null,
        array $transportStampTypes = [],
    ): self {
        $transportStampFactory ??= new MessageTransportStampFactory();
        $stampTypes = array_replace(MessageTransportStampDecider::DEFAULT_STAMP_TYPES, $transportStampTypes);

        $deciders = [
            new RetryPolicyStampDecider($retryPolicies, $messageType),
            new MessageTransportStampDecider(
                stampFactory: $transportStampFactory,
                commandResolvers: new TransportResolverMap(
                    sync: Command::class === $messageType ? $transports : null,
                    async: Command::class === $messageType ? $asyncTransports : null,
                ),
                queryResolvers: new TransportResolverMap(
                    sync: Query::class === $messageType ? $transports : null,
                ),
                eventResolvers: new TransportResolverMap(
                    sync: Event::class === $messageType ? $transports : null,
                    async: Event::class === $messageType ? $asyncTransports : null,
                ),
                stampTypes: $stampTypes,
            ),
            new MessageSerializerStampDecider($serializers, $messageType),
            new MessageMetadataStampDecider($metadata, $messageType),
        ];

        $deciders[] = new DispatchAfterCurrentBusStampDecider($dispatchAfter ?? DispatchAfterCurrentBusDecider::defaults());

        return new self($deciders);
    }
}
