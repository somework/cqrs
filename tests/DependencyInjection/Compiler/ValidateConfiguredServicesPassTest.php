<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateConfiguredServicesPass;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\DependencyInjection\Registration\ContainerHelper;
use SomeWork\CqrsBundle\Policy\NullMessageSerializer;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sprintf;

#[CoversClass(ValidateConfiguredServicesPass::class)]
#[CoversClass(ContainerHelper::class)]
final class ValidateConfiguredServicesPassTest extends TestCase
{
    public function test_a_missing_service_is_reported_with_the_option_that_names_it(): void
    {
        $container = $this->containerWith(['retry_policies' => ['command' => ['map' => [CreateTaskCommand::class => 'app.retry.payment']]]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('The service "app.retry.payment" configured at "somework_cqrs.retry_policies.command.map.%s" does not exist.', CreateTaskCommand::class));

        (new ValidateConfiguredServicesPass())->process($container);
    }

    public function test_a_missing_rate_limiter_is_reported_by_its_name(): void
    {
        $container = $this->containerWith(['rate_limiting' => ['default' => 'api']]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The rate limiter "api" configured at "somework_cqrs.rate_limiting.default" does not exist. Define it under "framework.rate_limiter".');

        (new ValidateConfiguredServicesPass())->process($container);
    }

    public function test_a_service_of_the_wrong_kind_is_reported_with_the_option_that_names_it(): void
    {
        $container = $this->containerWith(['retry_policies' => ['default' => NullMessageSerializer::class]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('The service "%s" configured at "somework_cqrs.retry_policies.default" must implement %s, %s does not.', NullMessageSerializer::class, RetryPolicy::class, NullMessageSerializer::class));

        (new ValidateConfiguredServicesPass())->process($container);
    }

    public function test_existing_services_pass_and_the_list_is_dropped(): void
    {
        $container = $this->containerWith(['serialization' => ['query' => ['default' => 'app.serializer']]]);
        $container->register('app.serializer', NullMessageSerializer::class);

        (new ValidateConfiguredServicesPass())->process($container);

        self::assertFalse($container->hasParameter(ContainerHelper::CONFIGURED_SERVICES));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function containerWith(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new CqrsExtension())->load([$config], $container);

        return $container;
    }
}
