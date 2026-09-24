<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use SomeWork\CqrsBundle\Attribute\Asynchronous;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_array;
use function is_string;
use function sprintf;

/**
 * Fails the build when a transport named in the configuration, or by #[Asynchronous(transport: ...)]
 * on a handled message, is not a Messenger transport.
 *
 * @internal
 */
final class ValidateTransportNamesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $configuredTransportNames = $container->hasParameter('somework_cqrs.transport_names')
            ? $container->getParameter('somework_cqrs.transport_names')
            : [];

        foreach (is_array($configuredTransportNames) ? $configuredTransportNames : [] as $transportName) {
            $transportName = (string) $transportName;

            if (!self::transportExists($container, $transportName)) {
                throw new InvalidConfigurationException(sprintf('Messenger transport "%s" configured for SomeWork CQRS is not defined.', $transportName));
            }
        }

        $this->validateAsynchronousAttributes($container);
    }

    /**
     * Messages are only known through their handlers (CqrsHandlerPass records them).
     */
    private function validateAsynchronousAttributes(ContainerBuilder $container): void
    {
        $metadata = $container->hasParameter('somework_cqrs.handler_metadata') ? $container->getParameter('somework_cqrs.handler_metadata') : [];
        $checked = [];

        foreach (is_array($metadata) ? $metadata : [] as $entries) {
            foreach (is_array($entries) ? $entries : [] as $entry) {
                $messageClass = is_array($entry) ? ($entry['message'] ?? null) : null;
                if (!is_string($messageClass) || isset($checked[$messageClass])) {
                    continue;
                }
                $checked[$messageClass] = true;

                $reflection = $container->getReflectionClass($messageClass, false);
                $attribute = $reflection?->getAttributes(Asynchronous::class)[0] ?? null;
                $transport = $attribute?->newInstance()->transport;

                if (null !== $transport && !self::transportExists($container, $transport)) {
                    throw new InvalidConfigurationException(sprintf('#[Asynchronous(transport: "%s")] on "%s" names a Messenger transport that is not defined.', $transport, $messageClass));
                }
            }
        }
    }

    private static function transportExists(ContainerBuilder $container, string $transportName): bool
    {
        $transportServiceId = sprintf('messenger.transport.%s', $transportName);

        return $container->hasDefinition($transportServiceId) || $container->hasAlias($transportServiceId);
    }
}
