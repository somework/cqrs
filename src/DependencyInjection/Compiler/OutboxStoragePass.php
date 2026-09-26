<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use SomeWork\CqrsBundle\Contract\Outbox\FailedOutboxMessages;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxMonitoring;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxSchema;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function is_a;
use function is_string;
use function sprintf;

/**
 * Lets the commands and the check that work on the storage itself (setup, failed, health, and the
 * relay's report of pending schema changes) use the base storage (the DBAL storage, or the one of
 * "somework_cqrs.outbox.storage") when the application decorates the outbox storage, e.g. to add
 * logging: the decorator does not implement the capabilities of the storage it wraps
 * (OutboxSchema, FailedOutboxMessages, OutboxMonitoring).
 *
 * An application that replaces the storage (another service under "somework_cqrs.outbox.storage")
 * keeps its storage everywhere; the features its storage does not implement then refuse to run.
 *
 * Runs before the optimization passes: decorators are only applied by DecoratorServicePass.
 *
 * @internal
 */
final class OutboxStoragePass implements CompilerPassInterface
{
    public const STORAGE_ID = 'somework_cqrs.outbox.storage';

    /** The configured storage, behind any decorator of STORAGE_ID. */
    public const BASE_STORAGE_ID = 'somework_cqrs.outbox.base_storage';

    public const DBAL_STORAGE_ID = 'somework_cqrs.outbox.dbal_storage';

    /** Interfaces autowired to the configured storage when it implements them. */
    public const CAPABILITIES = [OutboxSchema::class, FailedOutboxMessages::class, OutboxMonitoring::class];

    /** Service => [argument, interface the argument requires (null: any OutboxStorage, checked at runtime)] */
    private const CAPABILITY_CONSUMERS = [
        'somework_cqrs.outbox.setup_command' => ['$outboxStorage', null],
        'somework_cqrs.outbox.failed_command' => ['$outboxStorage', null],
        'somework_cqrs.outbox.health_checker' => ['$outboxStorage', null],
        'somework_cqrs.outbox.relay_command' => ['$table', OutboxSchema::class],
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(self::BASE_STORAGE_ID)) {
            return;
        }

        $base = (string) $container->getAlias(self::BASE_STORAGE_ID);
        $class = $this->assertIsStorage($container, $base);
        foreach (self::CAPABILITIES as $capability) {
            if (null !== $class && !is_a($class, $capability, true) && $container->hasAlias($capability) && self::BASE_STORAGE_ID === (string) $container->getAlias($capability)) {
                $container->removeAlias($capability);
            }
        }

        if (!$container->hasAlias(self::STORAGE_ID) || $base !== (string) $container->getAlias(self::STORAGE_ID)) {
            return;
        }

        foreach (self::CAPABILITY_CONSUMERS as $id => [$argument, $required]) {
            if (!$container->hasDefinition($id)) {
                continue;
            }

            // A storage without the capability (e.g. a custom storage that has no schema) is not passed:
            // the consumer then does without it.
            $container->getDefinition($id)->setArgument($argument, null === $required || (null !== $class && is_a($class, $required, true)) ? new Reference($base) : null);
        }
    }

    /**
     * @return class-string|null The class of the storage, when it is known
     */
    private function assertIsStorage(ContainerBuilder $container, string $id): ?string
    {
        if (!$container->has($id)) {
            // Reported by ValidateConfiguredServicesPass.
            return null;
        }

        // A service defined by its class name has no class until ResolveClassPass.
        $class = $container->findDefinition($id)->getClass() ?? $id;
        $class = $container->getParameterBag()->resolveValue($class);
        if (is_string($class) && class_exists($class) && !is_a($class, OutboxStorage::class, true)) {
            throw new InvalidConfigurationException(sprintf('The outbox storage "%s" configured at "somework_cqrs.outbox.storage" must implement %s, %s does not.', $id, OutboxStorage::class, $class));
        }

        return is_string($class) && class_exists($class) ? $class : null;
    }
}
