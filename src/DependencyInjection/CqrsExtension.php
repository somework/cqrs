<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\ToolEvents;
use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Attribute\AsQueryHandler;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\CommandHandler;
use SomeWork\CqrsBundle\Contract\EventHandler;
use SomeWork\CqrsBundle\Contract\QueryHandler;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CqrsHandlerPass;
use SomeWork\CqrsBundle\DependencyInjection\Registration\AllowNoHandlerMiddlewareRegistrar;
use SomeWork\CqrsBundle\DependencyInjection\Registration\BusInterfaceRegistrar;
use SomeWork\CqrsBundle\DependencyInjection\Registration\BusWiringRegistrar;
use SomeWork\CqrsBundle\DependencyInjection\Registration\ContainerHelper;
use SomeWork\CqrsBundle\DependencyInjection\Registration\DispatchAfterCurrentBusRegistrar;
use SomeWork\CqrsBundle\DependencyInjection\Registration\DispatchModeRegistrar;
use SomeWork\CqrsBundle\DependencyInjection\Registration\MetadataRegistrar;
use SomeWork\CqrsBundle\DependencyInjection\Registration\NamingRegistrar;
use SomeWork\CqrsBundle\DependencyInjection\Registration\OutboxRegistrar;
use SomeWork\CqrsBundle\DependencyInjection\Registration\RateLimitRegistrar;
use SomeWork\CqrsBundle\DependencyInjection\Registration\RetryPolicyRegistrar;
use SomeWork\CqrsBundle\DependencyInjection\Registration\SerializerRegistrar;
use SomeWork\CqrsBundle\DependencyInjection\Registration\StampsDeciderRegistrar;
use SomeWork\CqrsBundle\DependencyInjection\Registration\TransportRegistrar;
use SomeWork\CqrsBundle\Health\HealthChecker;
use SomeWork\CqrsBundle\Messenger\CausationIdMiddleware;
use SomeWork\CqrsBundle\Support\CausationIdContext;
use SomeWork\CqrsBundle\Support\MessageTypeLocatorResetter;
use SomeWork\CqrsBundle\Support\StampDecider;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\Key;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\RateLimiter\RateLimiterFactory;

use function array_filter;
use function class_exists;
use function sprintf;

/** @internal */
final class CqrsExtension extends Extension
{
    /** @var Closure(string): bool */
    private readonly Closure $classExists;

    /**
     * @param (Closure(string): bool)|null $classExists Detects optional dependencies; injectable for tests
     */
    public function __construct(?Closure $classExists = null)
    {
        $this->classExists = $classExists ?? static fn (string $class): bool => class_exists($class);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        /** @var array<string, mixed> $config */
        $config = $this->processConfiguration($configuration, $configs);

        /** @var string $defaultBusId */
        $defaultBusId = $config['default_bus'] ?? 'messenger.default_bus';
        $container->setParameter('somework_cqrs.default_bus', $defaultBusId);
        $container->setParameter('somework_cqrs.handler_metadata', []);

        /** @var array<string, string|null> $buses */
        $buses = $config['buses'] ?? [];
        foreach (['command', 'query', 'event', 'command_async', 'event_async'] as $busKey) {
            if (isset($buses[$busKey]) && '' !== $buses[$busKey]) {
                $container->setParameter('somework_cqrs.bus.'.$busKey, $buses[$busKey]);
            }
        }

        /* @phpstan-ignore argument.type */
        $this->guardAsyncBusConfiguration($config);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.php');

        $resetter = new Definition(MessageTypeLocatorResetter::class);
        $resetter->addTag('kernel.reset', ['method' => 'reset']);
        $resetter->setPublic(false);
        $container->setDefinition('somework_cqrs.message_type_locator_resetter', $resetter);

        $causationCtx = new Definition(CausationIdContext::class);
        $causationCtx->addTag('kernel.reset', ['method' => 'reset']);
        $causationCtx->setPublic(false);
        $container->setDefinition('somework_cqrs.causation_id_context', $causationCtx);
        $container->setAlias(CausationIdContext::class, 'somework_cqrs.causation_id_context')->setPublic(false);

        $causationMiddleware = new Definition(CausationIdMiddleware::class);
        $causationMiddleware->setArgument('$causationIdContext', new Reference('somework_cqrs.causation_id_context'));
        $causationMiddleware->setPublic(false);
        $container->setDefinition('somework_cqrs.messenger.middleware.causation_id', $causationMiddleware);

        $container->setAlias(
            'somework_cqrs.exponential_backoff_retry_policy',
            \SomeWork\CqrsBundle\Support\ExponentialBackoffRetryPolicy::class,
        )->setPublic(false);

        $container->registerForAutoconfiguration(StampDecider::class)
            ->addTag('somework_cqrs.dispatch_stamp_decider');

        $container->registerForAutoconfiguration(HealthChecker::class)
            ->addTag('somework_cqrs.health_checker');

        $this->registerHandlerAutoconfiguration($container);

        $helper = new ContainerHelper();

        (new NamingRegistrar($helper))->register($container, $config['naming']);
        (new RetryPolicyRegistrar($helper))->register($container, $config['retry_policies']);
        (new SerializerRegistrar($helper))->register($container, $config['serialization']);
        (new MetadataRegistrar($helper))->register($container, $config['metadata']);
        (new TransportRegistrar())->register($container, $config['transports']);
        (new DispatchModeRegistrar())->register($container, $config['dispatch_modes']);
        (new DispatchAfterCurrentBusRegistrar($helper))->register($container, $config['async']['dispatch_after_current_bus']);
        $rateLimitingActive = $this->isRateLimitingActive($config['rate_limiting']);
        if ($rateLimitingActive) {
            (new RateLimitRegistrar())->register($container, $config['rate_limiting']);
        }

        if (true === $config['outbox']['enabled']) {
            if (!($this->classExists)(Connection::class)) {
                throw new InvalidConfigurationException('Outbox is enabled (somework_cqrs.outbox.enabled: true) but doctrine/dbal is not installed. Run "composer require doctrine/dbal" or set somework_cqrs.outbox.enabled to false.');
            }
            (new OutboxRegistrar())->register($container, $config['outbox'], ($this->classExists)(ToolEvents::class));
        }

        $idempotencyConfig = $config['idempotency'];
        $idempotencyConfig['enabled'] = true === $idempotencyConfig['enabled']
            && ($this->classExists)(DeduplicateStamp::class)
            && ($this->classExists)(Key::class);

        $rateLimitConfig = $config['rate_limiting'];
        $rateLimitConfig['enabled'] = $rateLimitingActive;

        (new StampsDeciderRegistrar($helper))->register($container, $config['buses'], $idempotencyConfig, $config['causation_id'], $config['sequence'], $rateLimitConfig);
        (new AllowNoHandlerMiddlewareRegistrar())->register($container, $config['buses'], $defaultBusId);
        (new BusWiringRegistrar())->register($container, $config['buses'], $defaultBusId);
        (new BusInterfaceRegistrar())->register($container);

        $container->setParameter('somework_cqrs.retry_strategy.transports', $config['retry_strategy']['transports']);
        $container->setParameter('somework_cqrs.retry_strategy.jitter', $config['retry_strategy']['jitter']);
        $container->setParameter('somework_cqrs.retry_strategy.max_delay', $config['retry_strategy']['max_delay']);

        $container->setParameter('somework_cqrs.idempotency.enabled', $config['idempotency']['enabled']);
        $container->setParameter('somework_cqrs.idempotency.ttl', $config['idempotency']['ttl']);

        $container->setParameter('somework_cqrs.causation_id.enabled', $config['causation_id']['enabled']);
        $container->setParameter('somework_cqrs.causation_id.buses', $config['causation_id']['buses']);

        $container->setParameter('somework_cqrs.sequence.enabled', $config['sequence']['enabled']);

        $container->setParameter('somework_cqrs.rate_limiting.enabled', $config['rate_limiting']['enabled']);

        $container->setParameter('somework_cqrs.outbox.enabled', $config['outbox']['enabled']);
        $container->setParameter('somework_cqrs.outbox.table_name', $config['outbox']['table_name']);
    }

    public function getAlias(): string
    {
        return 'somework_cqrs';
    }

    /**
     * Rate limiting only needs symfony/rate-limiter once limiters are mapped to messages;
     * with no mapping the feature is simply inactive, so the default config never fails.
     *
     * @param array{enabled: bool, command: array{map: array<string, string>}, query: array{map: array<string, string>}, event: array{map: array<string, string>}} $config
     */
    private function isRateLimitingActive(array $config): bool
    {
        if (true !== $config['enabled']) {
            return false;
        }

        $hasMappings = [] !== $config['command']['map'] || [] !== $config['query']['map'] || [] !== $config['event']['map'];

        if (!$hasMappings) {
            return false;
        }

        if (!($this->classExists)(RateLimiterFactory::class)) {
            throw new InvalidConfigurationException('Rate limiters are mapped under "somework_cqrs.rate_limiting" but symfony/rate-limiter is not installed. Run "composer require symfony/rate-limiter" or remove the mappings.');
        }

        return true;
    }

    /**
     * Handler tags only carry a bus when one is declared explicitly; CqrsHandlerPass assigns the
     * configured buses once the whole container (including framework aliases) is available.
     */
    private function registerHandlerAutoconfiguration(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            AsCommandHandler::class,
            static function (ChildDefinition $definition, AsCommandHandler $attribute): void {
                $definition->addTag('messenger.message_handler', self::handlerTag($attribute->command, $attribute->bus, 'command'));
            }
        );

        $container->registerAttributeForAutoconfiguration(
            AsQueryHandler::class,
            static function (ChildDefinition $definition, AsQueryHandler $attribute): void {
                $definition->addTag('messenger.message_handler', self::handlerTag($attribute->query, $attribute->bus, 'query'));
            }
        );

        $container->registerAttributeForAutoconfiguration(
            AsEventHandler::class,
            static function (ChildDefinition $definition, AsEventHandler $attribute): void {
                $definition->addTag('messenger.message_handler', self::handlerTag($attribute->event, $attribute->bus, 'event'));
            }
        );

        foreach ([CommandHandler::class => 'command', QueryHandler::class => 'query', EventHandler::class => 'event'] as $interface => $type) {
            $container->registerForAutoconfiguration($interface)
                ->addTag(CqrsHandlerPass::INTERFACE_TAG, ['method' => '__invoke', 'type' => $type]);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function handlerTag(string $message, ?string $bus, string $type): array
    {
        $tag = [
            'handles' => $message,
            CqrsHandlerPass::TYPE_ATTRIBUTE => $type,
        ];

        if (null !== $bus && '' !== $bus) {
            $tag['bus'] = $bus;
        }

        return $tag;
    }

    /**
     * @param array{
     *     buses: array{
     *         command?: string|null,
     *         command_async?: string|null,
     *         query?: string|null,
     *         event?: string|null,
     *         event_async?: string|null,
     *     },
     *     dispatch_modes: array{
     *         command: array{default: string, map: array<string, string>},
     *         event: array{default: string, map: array<string, string>},
     *     },
     *     transports: array{
     *         command: array{default: list<string>, map: array<string, list<string>>},
     *         command_async: array{default: list<string>, map: array<string, list<string>>},
     *         query: array{default: list<string>, map: array<string, list<string>>},
     *         event: array{default: list<string>, map: array<string, list<string>>},
     *         event_async: array{default: list<string>, map: array<string, list<string>>},
     *     },
     * } $config
     */
    private function guardAsyncBusConfiguration(array $config): void
    {
        $commandAsyncBus = $config['buses']['command_async'] ?? null;
        $eventAsyncBus = $config['buses']['event_async'] ?? null;

        $commandAsyncSources = $this->collectAsyncSources(
            $config['dispatch_modes']['command'],
            $config['transports']['command_async']
        );
        if (null === $commandAsyncBus && $this->hasAsyncConfiguration($commandAsyncSources)) {
            $this->throwMissingAsyncBusException('command', $commandAsyncSources, 'command_async');
        }

        $eventAsyncSources = $this->collectAsyncSources(
            $config['dispatch_modes']['event'],
            $config['transports']['event_async']
        );
        if (null === $eventAsyncBus && $this->hasAsyncConfiguration($eventAsyncSources)) {
            $this->throwMissingAsyncBusException('event', $eventAsyncSources, 'event_async');
        }
    }

    /**
     * @param array{default: string, map: array<string, string>}             $dispatchConfig
     * @param array{default: list<string>, map: array<string, list<string>>} $transportConfig
     *
     * @return array{
     *     dispatch_default: bool,
     *     dispatch_messages: list<string>,
     *     transport_default: list<string>,
     *     transport_messages: array<string, list<string>>,
     * }
     */
    private function collectAsyncSources(array $dispatchConfig, array $transportConfig): array
    {
        $dispatchMessages = [];

        foreach ($dispatchConfig['map'] as $messageClass => $mode) {
            if (DispatchMode::ASYNC->value === $mode) {
                $dispatchMessages[] = $messageClass;
            }
        }

        return [
            'dispatch_default' => DispatchMode::ASYNC->value === $dispatchConfig['default'],
            'dispatch_messages' => $dispatchMessages,
            'transport_default' => $transportConfig['default'],
            'transport_messages' => array_filter(
                $transportConfig['map'],
                static fn (array $transports): bool => [] !== $transports
            ),
        ];
    }

    /**
     * @param array{
     *     dispatch_default: bool,
     *     dispatch_messages: list<string>,
     *     transport_default: list<string>,
     *     transport_messages: array<string, list<string>>,
     * } $sources
     */
    private function hasAsyncConfiguration(array $sources): bool
    {
        return $sources['dispatch_default']
            || [] !== $sources['dispatch_messages']
            || [] !== $sources['transport_default']
            || [] !== $sources['transport_messages'];
    }

    /**
     * @param array{
     *     dispatch_default: bool,
     *     dispatch_messages: list<string>,
     *     transport_default: list<string>,
     *     transport_messages: array<string, list<string>>,
     * } $sources
     */
    private function throwMissingAsyncBusException(string $type, array $sources, string $busKey): void
    {
        $typeLabel = 'command' === $type ? 'commands' : 'events';

        $parts = [];
        if ($sources['dispatch_default']) {
            $parts[] = 'the default dispatch mode is "async"';
        }
        if ([] !== $sources['dispatch_messages']) {
            $parts[] = sprintf('async dispatch mode map entries: %s', implode(', ', $sources['dispatch_messages']));
        }
        if ([] !== $sources['transport_default']) {
            $parts[] = sprintf('async transport defaults: %s', implode(', ', $sources['transport_default']));
        }
        if ([] !== $sources['transport_messages']) {
            $entries = [];
            foreach ($sources['transport_messages'] as $messageClass => $transports) {
                $entries[] = sprintf('%s => [%s]', $messageClass, implode(', ', $transports));
            }

            $parts[] = sprintf('async transport map entries: %s', implode(', ', $entries));
        }

        $details = implode(' and ', $parts);

        $message = sprintf(
            'Asynchronous dispatch is configured for %s (%s), but "somework_cqrs.buses.%s" is null. Define the Messenger bus id used for async %s before the container is compiled.',
            $typeLabel,
            $details,
            $busKey,
            $typeLabel,
        );

        throw new InvalidConfigurationException($message);
    }
}
