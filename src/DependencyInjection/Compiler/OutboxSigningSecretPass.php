<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Signs outbox rows with "kernel.secret" (framework.secret) unless outbox.signing.secret is set:
 * that parameter is only known once FrameworkBundle's extension has run.
 *
 * @internal
 */
final class OutboxSigningSecretPass implements CompilerPassInterface
{
    public const SIGNER_ID = 'somework_cqrs.outbox.signer';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::SIGNER_ID)) {
            return;
        }

        $signer = $container->getDefinition(self::SIGNER_ID);
        if (null !== $signer->getArgument('$secret')) {
            return;
        }

        if (!$container->hasParameter('kernel.secret')) {
            throw new InvalidConfigurationException('Outbox signing ("somework_cqrs.outbox.signing.enabled") needs a secret: set "framework.secret" or "somework_cqrs.outbox.signing.secret", or disable signing.');
        }

        $signer->setArgument('$secret', '%kernel.secret%');
    }
}
