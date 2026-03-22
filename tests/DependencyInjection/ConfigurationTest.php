<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    public function test_retry_strategy_transports_accepts_valid_mapping(): void
    {
        $config = $this->processConfiguration([
            'retry_strategy' => [
                'transports' => [
                    'async' => 'command',
                    'events_async' => 'event',
                ],
            ],
        ]);

        self::assertSame([
            'async' => 'command',
            'events_async' => 'event',
        ], $config['retry_strategy']['transports']);
    }

    public function test_retry_strategy_transports_defaults_to_empty_array(): void
    {
        $config = $this->processConfiguration([]);

        self::assertSame([], $config['retry_strategy']['transports']);
    }

    public function test_retry_strategy_jitter_defaults_to_zero(): void
    {
        $config = $this->processConfiguration([]);

        self::assertSame(0.0, $config['retry_strategy']['jitter']);
    }

    public function test_retry_strategy_jitter_accepts_float(): void
    {
        $config = $this->processConfiguration([
            'retry_strategy' => [
                'jitter' => 0.5,
            ],
        ]);

        self::assertSame(0.5, $config['retry_strategy']['jitter']);
    }

    public function test_retry_strategy_max_delay_defaults_to_zero(): void
    {
        $config = $this->processConfiguration([]);

        self::assertSame(0, $config['retry_strategy']['max_delay']);
    }

    public function test_retry_strategy_max_delay_accepts_positive_integer(): void
    {
        $config = $this->processConfiguration([
            'retry_strategy' => [
                'max_delay' => 60000,
            ],
        ]);

        self::assertSame(60000, $config['retry_strategy']['max_delay']);
    }

    public function test_retry_strategy_transports_rejects_invalid_type(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->processConfiguration([
            'retry_strategy' => [
                'transports' => [
                    'async' => 'invalid_type',
                ],
            ],
        ]);
    }

    public function test_idempotency_enabled_defaults_to_true(): void
    {
        $config = $this->processConfiguration([]);

        self::assertTrue($config['idempotency']['enabled']);
    }

    public function test_idempotency_ttl_defaults_to_300(): void
    {
        $config = $this->processConfiguration([]);

        self::assertSame(300, $config['idempotency']['ttl']);
    }

    public function test_idempotency_enabled_accepts_false(): void
    {
        $config = $this->processConfiguration([
            'idempotency' => [
                'enabled' => false,
            ],
        ]);

        self::assertFalse($config['idempotency']['enabled']);
    }

    public function test_idempotency_ttl_accepts_custom_value(): void
    {
        $config = $this->processConfiguration([
            'idempotency' => [
                'ttl' => 600,
            ],
        ]);

        self::assertSame(600, $config['idempotency']['ttl']);
    }

    public function test_causation_id_defaults(): void
    {
        $config = $this->processConfiguration([]);

        self::assertTrue($config['causation_id']['enabled']);
        self::assertSame([], $config['causation_id']['buses']);
    }

    public function test_causation_id_disabled(): void
    {
        $config = $this->processConfiguration([
            'causation_id' => [
                'enabled' => false,
            ],
        ]);

        self::assertFalse($config['causation_id']['enabled']);
    }

    public function test_causation_id_scoped_buses(): void
    {
        $config = $this->processConfiguration([
            'causation_id' => [
                'buses' => ['messenger.bus.commands'],
            ],
        ]);

        self::assertSame(['messenger.bus.commands'], $config['causation_id']['buses']);
    }

    public function test_causation_id_accepts_multiple_buses(): void
    {
        $config = $this->processConfiguration([
            'causation_id' => [
                'buses' => [
                    'messenger.bus.commands',
                    'messenger.bus.events',
                    'messenger.bus.queries',
                ],
            ],
        ]);

        self::assertSame(
            ['messenger.bus.commands', 'messenger.bus.events', 'messenger.bus.queries'],
            $config['causation_id']['buses'],
        );
    }

    public function test_causation_id_disabled_with_buses_still_processes(): void
    {
        $config = $this->processConfiguration([
            'causation_id' => [
                'enabled' => false,
                'buses' => ['messenger.bus.commands'],
            ],
        ]);

        self::assertFalse($config['causation_id']['enabled']);
        self::assertSame(['messenger.bus.commands'], $config['causation_id']['buses']);
    }

    public function test_causation_id_enabled_accepts_explicit_true(): void
    {
        $config = $this->processConfiguration([
            'causation_id' => [
                'enabled' => true,
            ],
        ]);

        self::assertTrue($config['causation_id']['enabled']);
        self::assertSame([], $config['causation_id']['buses']);
    }

    public function test_outbox_default_config(): void
    {
        $config = $this->processConfiguration([]);

        self::assertFalse($config['outbox']['enabled']);
        self::assertSame('somework_cqrs_outbox', $config['outbox']['table_name']);
    }

    public function test_outbox_enabled_config(): void
    {
        $config = $this->processConfiguration([
            'outbox' => [
                'enabled' => true,
            ],
        ]);

        self::assertTrue($config['outbox']['enabled']);
    }

    public function test_outbox_custom_table_name(): void
    {
        $config = $this->processConfiguration([
            'outbox' => [
                'table_name' => 'my_outbox',
            ],
        ]);

        self::assertSame('my_outbox', $config['outbox']['table_name']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function processConfiguration(array $config): array
    {
        $processor = new Processor();

        return $processor->processConfiguration(new Configuration(), ['somework_cqrs' => $config]);
    }
}
