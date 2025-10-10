<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use ReflectionClass;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\DispatchModeDecider;
use SomeWork\CqrsBundle\Registry\HandlerDescriptor;
use SomeWork\CqrsBundle\Registry\HandlerRegistry;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusDecider;
use SomeWork\CqrsBundle\Support\MessageMetadataProviderResolver;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Support\TransportMappingProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function class_exists;
use function get_debug_type;
use function implode;
use function in_array;
use function is_object;
use function is_string;
use function sprintf;

#[AsCommand(
    name: 'somework:cqrs:list',
    description: 'List CQRS commands, queries, and events registered in Messenger.',
)]
final class ListHandlersCommand extends Command
{
    /**
     * @var array<string, string>
     */
    private const SECTION_TITLES = [
        'command' => 'Commands',
        'query' => 'Queries',
        'event' => 'Events',
    ];

    /**
     * @var array<string, RetryPolicyResolver>
     */
    private readonly array $retryResolvers;

    /**
     * @var array<string, MessageSerializerResolver>
     */
    private readonly array $serializerResolvers;

    /**
     * @var array<string, MessageMetadataProviderResolver>
     */
    private readonly array $metadataResolvers;

    /**
     * @var array<string, MessageTransportResolver>
     */
    private readonly array $transportResolvers;

    /**
     * @var array<string, MessageTransportResolver|null>
     */
    private readonly array $asyncTransportResolvers;

    /**
     * @var array<string, array{default: list<string>, map: array<class-string, list<string>>}>
     */
    private readonly array $transportMappings;

    public function __construct(
        private readonly HandlerRegistry $registry,
        private readonly DispatchModeDecider $dispatchModeDecider,
        private readonly DispatchAfterCurrentBusDecider $dispatchAfterCurrentBusDecider,
        #[Autowire(service: 'somework_cqrs.retry.command_resolver')]
        RetryPolicyResolver $commandRetryResolver,
        #[Autowire(service: 'somework_cqrs.retry.query_resolver')]
        RetryPolicyResolver $queryRetryResolver,
        #[Autowire(service: 'somework_cqrs.retry.event_resolver')]
        RetryPolicyResolver $eventRetryResolver,
        #[Autowire(service: 'somework_cqrs.serializer.command_resolver')]
        MessageSerializerResolver $commandSerializerResolver,
        #[Autowire(service: 'somework_cqrs.serializer.query_resolver')]
        MessageSerializerResolver $querySerializerResolver,
        #[Autowire(service: 'somework_cqrs.serializer.event_resolver')]
        MessageSerializerResolver $eventSerializerResolver,
        #[Autowire(service: 'somework_cqrs.metadata.command_resolver')]
        MessageMetadataProviderResolver $commandMetadataResolver,
        #[Autowire(service: 'somework_cqrs.metadata.query_resolver')]
        MessageMetadataProviderResolver $queryMetadataResolver,
        #[Autowire(service: 'somework_cqrs.metadata.event_resolver')]
        MessageMetadataProviderResolver $eventMetadataResolver,
        #[Autowire(service: 'somework_cqrs.transports.command_resolver')]
        MessageTransportResolver $commandTransportResolver,
        #[Autowire(service: 'somework_cqrs.transports.query_resolver')]
        MessageTransportResolver $queryTransportResolver,
        #[Autowire(service: 'somework_cqrs.transports.event_resolver')]
        MessageTransportResolver $eventTransportResolver,
        TransportMappingProvider $transportMappingProvider,
        #[Autowire(service: 'somework_cqrs.transports.command_async_resolver')]
        ?MessageTransportResolver $commandAsyncTransportResolver = null,
        #[Autowire(service: 'somework_cqrs.transports.event_async_resolver')]
        ?MessageTransportResolver $eventAsyncTransportResolver = null,
    ) {
        parent::__construct();

        $this->retryResolvers = [
            'command' => $commandRetryResolver,
            'query' => $queryRetryResolver,
            'event' => $eventRetryResolver,
        ];

        $this->serializerResolvers = [
            'command' => $commandSerializerResolver,
            'query' => $querySerializerResolver,
            'event' => $eventSerializerResolver,
        ];

        $this->metadataResolvers = [
            'command' => $commandMetadataResolver,
            'query' => $queryMetadataResolver,
            'event' => $eventMetadataResolver,
        ];

        $this->transportResolvers = [
            'command' => $commandTransportResolver,
            'query' => $queryTransportResolver,
            'event' => $eventTransportResolver,
        ];

        $this->asyncTransportResolvers = [
            'command' => $commandAsyncTransportResolver,
            'event' => $eventAsyncTransportResolver,
        ];

        $this->transportMappings = $transportMappingProvider->all();
    }

    protected function configure(): void
    {
        $this
            ->addOption('details', mode: InputOption::VALUE_NONE, description: 'Display resolved configuration details for each handler.')
            ->addOption('type', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, description: 'Filter by message type (command, query, event).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var array<int, string>|string|null $requestedTypes */
        $requestedTypes = $input->getOption('type');
        $types = $this->normaliseTypes($requestedTypes);
        $showDetails = (bool) $input->getOption('details');

        $rowsByType = [];
        foreach ($types as $type) {
            $descriptors = $this->registry->byType($type);
            $rows = [];
            foreach ($descriptors as $descriptor) {
                $rows[] = $this->formatDescriptor($descriptor, $showDetails);
            }

            if ([] !== $rows) {
                usort(
                    $rows,
                    static fn (array $a, array $b): int => [$a[1], $a[2]] <=> [$b[1], $b[2]]
                );

                $rowsByType[$type] = $rows;
            }
        }

        if ([] === $rowsByType) {
            $io->warning('No CQRS handlers were found for the given filters.');

            return self::SUCCESS;
        }

        $typesToDisplay = array_keys($rowsByType);

        foreach ($typesToDisplay as $index => $type) {
            $io->section(self::SECTION_TITLES[$type] ?? sprintf('%ss', ucfirst($type)));

            $this->renderTable($output, $rowsByType[$type], $showDetails);

            if ($index < count($typesToDisplay) - 1) {
                $io->newLine();
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int, string>|string|null $requested
     *
     * @return list<'command'|'query'|'event'>
     */
    private function normaliseTypes(array|string|null $requested): array
    {
        $available = ['command', 'query', 'event'];

        if (null === $requested || [] === $requested) {
            return $available;
        }

        if (is_string($requested)) {
            $requested = [$requested];
        }

        $types = [];
        foreach ($requested as $type) {
            $type = strtolower($type);
            if (in_array($type, $available, true)) {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    private function formatDescriptor(HandlerDescriptor $descriptor, bool $showDetails): array
    {
        $row = [
            ucfirst($descriptor->type),
            $this->registry->getDisplayName($descriptor),
            $descriptor->handlerClass,
            $descriptor->serviceId,
            $descriptor->bus ?? 'default',
        ];

        if (!$showDetails) {
            return $row;
        }

        $details = $this->describeDescriptor($descriptor);

        return [...$row, ...$details];
    }

    /**
     * @return list<string>
     */
    private function describeDescriptor(HandlerDescriptor $descriptor): array
    {
        $message = $this->instantiateMessage($descriptor->messageClass);

        $dispatchMode = $this->describeDispatchMode($message);
        $asyncDefers = $this->describeAsyncDeferral($descriptor->type, $message);
        $syncTransports = $this->describeTransports($descriptor->type, $descriptor->messageClass, $message, false);
        $asyncTransports = $this->describeTransports($descriptor->type, $descriptor->messageClass, $message, true);

        $retryResolver = $this->retryResolvers[$descriptor->type] ?? null;
        $retry = $this->describeResolvedService(
            $retryResolver,
            $message,
            static fn (RetryPolicyResolver $resolver, object $msg): object => $resolver->resolveFor($msg)
        );

        $serializerResolver = $this->serializerResolvers[$descriptor->type] ?? null;
        $serializer = $this->describeResolvedService(
            $serializerResolver,
            $message,
            static fn (MessageSerializerResolver $resolver, object $msg): object => $resolver->resolveFor($msg)
        );

        $metadataResolver = $this->metadataResolvers[$descriptor->type] ?? null;
        $metadata = $this->describeResolvedService(
            $metadataResolver,
            $message,
            static fn (MessageMetadataProviderResolver $resolver, object $msg): object => $resolver->resolveFor($msg)
        );

        return [$dispatchMode, $asyncDefers, $syncTransports, $asyncTransports, $retry, $serializer, $metadata];
    }

    private function describeTransports(string $type, string $messageClass, ?object $message, bool $async): string
    {
        if (null === $message) {
            return $this->describeTransportsFromMapping($type, $messageClass, $async);
        }

        $resolver = $async
            ? ($this->asyncTransportResolvers[$type] ?? null)
            : ($this->transportResolvers[$type] ?? null);

        if (null === $resolver) {
            return 'n/a';
        }

        try {
            $transports = $resolver->resolveFor($message);
        } catch (\Throwable $exception) {
            return sprintf('error: %s', $exception->getMessage());
        }

        return $this->formatTransports($transports);
    }

    private function describeTransportsFromMapping(string $type, string $messageClass, bool $async): string
    {
        $mappingKey = $this->resolveTransportMappingKey($type, $async);

        if (null === $mappingKey) {
            return 'n/a';
        }

        $mapping = $this->transportMappings[$mappingKey] ?? ['default' => [], 'map' => []];

        $transports = $mapping['map'][$messageClass] ?? null;

        if (null === $transports) {
            $transports = $mapping['default'];
        }

        return $this->formatTransports($transports);
    }

    private function resolveTransportMappingKey(string $type, bool $async): ?string
    {
        return match ($type) {
            'command' => $async ? 'command_async' : 'command',
            'query' => $async ? null : 'query',
            'event' => $async ? 'event_async' : 'event',
            default => null,
        };
    }

    /**
     * @param list<string>|null $transports
     */
    private function formatTransports(?array $transports): string
    {
        if (null === $transports || [] === $transports) {
            return 'None';
        }

        return implode(', ', $transports);
    }

    private function describeDispatchMode(?object $message): string
    {
        if (null === $message) {
            return 'n/a';
        }

        return $this->dispatchModeDecider->resolve($message, DispatchMode::DEFAULT)->value;
    }

    private function describeAsyncDeferral(string $type, ?object $message): string
    {
        if (null === $message) {
            return 'n/a';
        }

        if (!in_array($type, ['command', 'event'], true)) {
            return 'n/a';
        }

        return $this->dispatchAfterCurrentBusDecider->shouldDefer($message) ? 'yes' : 'no';
    }

    /**
     * @template T
     *
     * @param T|null                      $resolver
     * @param callable(T, object): object $callback
     */
    private function describeResolvedService(mixed $resolver, ?object $message, callable $callback): string
    {
        if (null === $resolver || null === $message) {
            return 'n/a';
        }

        try {
            $service = $callback($resolver, $message);
        } catch (\Throwable $exception) {
            return sprintf('error: %s', $exception->getMessage());
        }

        if (!is_object($service)) {
            return get_debug_type($service);
        }

        return $service::class;
    }

    private function instantiateMessage(string $messageClass): ?object
    {
        if (!class_exists($messageClass)) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($messageClass);
        } catch (\ReflectionException) {
            return null;
        }

        if ($reflection->isInterface() || $reflection->isAbstract()) {
            return null;
        }

        try {
            return $reflection->newInstanceWithoutConstructor();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<array<int, string>> $rows
     */
    private function renderTable(OutputInterface $output, array $rows, bool $showDetails): void
    {
        $headers = ['Type', 'Message', 'Handler', 'Service Id', 'Bus'];

        if ($showDetails) {
            $headers = [...$headers, 'Dispatch Mode', 'Async Defers', 'Sync Transports', 'Async Transports', 'Retry Policy', 'Serializer', 'Metadata Provider'];
        }

        $table = new Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->setStyle('symfony-style-guide');
        $table->render();
    }
}
