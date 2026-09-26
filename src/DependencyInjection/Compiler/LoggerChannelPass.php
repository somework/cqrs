<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function is_array;
use function str_starts_with;

/**
 * Makes the services of the bundle log on the "cqrs" channel of MonologBundle, which the
 * extension declares when MonologBundle is installed. Without it they keep the "logger" service.
 *
 * Runs after the passes of the bundle that add services with a logger, so it does not depend on
 * the order in which MonologBundle's own channel pass runs.
 *
 * @internal
 */
final class LoggerChannelPass implements CompilerPassInterface
{
    public const CHANNEL = 'cqrs';

    private const CHANNEL_LOGGER = 'monolog.logger.'.self::CHANNEL;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(self::CHANNEL_LOGGER)) {
            return;
        }

        foreach ($container->getDefinitions() as $definition) {
            if (!str_starts_with((string) $definition->getClass(), 'SomeWork\\CqrsBundle\\')) {
                continue;
            }

            $definition->setArguments(self::replace($definition->getArguments()));
            self::replaceInCalls($definition);
        }
    }

    private static function replaceInCalls(Definition $definition): void
    {
        $calls = [];
        foreach ($definition->getMethodCalls() as $call) {
            $call[1] = self::replace($call[1]);
            $calls[] = $call;
        }
        $definition->setMethodCalls($calls);
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    private static function replace(array $arguments): array
    {
        foreach ($arguments as $key => $argument) {
            if ($argument instanceof Reference && 'logger' === (string) $argument) {
                $arguments[$key] = new Reference(self::CHANNEL_LOGGER, $argument->getInvalidBehavior());
            } elseif (is_array($argument)) {
                $arguments[$key] = self::replace($argument);
            }
        }

        return $arguments;
    }
}
