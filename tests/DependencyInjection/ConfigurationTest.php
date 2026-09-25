<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\DependencyInjection\Configuration;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

use function sprintf;

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
        $this->expectException(InvalidConfigurationException::class);

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
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTableNames(): iterable
    {
        yield 'statement separator' => ['outbox;drop', 'use letters, digits and underscores'];
        yield 'trailing newline' => ["outbox\n", 'use letters, digits and underscores'];
        yield 'two dots' => ['a.b.c', 'use letters, digits and underscores'];
        yield 'reserved word' => ['user', 'it is a reserved SQL word'];
    }

    #[DataProvider('invalidTableNames')]
    public function test_rejects_invalid_outbox_table_names(string $name, string $error): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($error);

        $this->processConfiguration(['outbox' => ['table_name' => $name]]);
    }

    public function test_map_keys_drop_a_leading_backslash(): void
    {
        $config = $this->processConfiguration([
            'retry_policies' => ['command' => ['map' => ['\\'.CreateTaskCommand::class => 'app.retry']]],
            'transports' => ['event' => ['map' => ['\\'.Event::class => 'async']]],
        ]);

        self::assertSame([CreateTaskCommand::class => 'app.retry'], $config['retry_policies']['command']['map']);
        self::assertSame([Event::class => ['async']], $config['transports']['event']['map']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unknownMapKeys(): iterable
    {
        $typo = 'App\\Command\\CreateTaks';

        yield 'retry policy' => [['retry_policies' => ['command' => ['map' => [$typo => 'app.retry']]]], 'somework_cqrs.retry_policies.command.map'];
        yield 'serializer' => [['serialization' => ['query' => ['map' => [$typo => 'app.serializer']]]], 'somework_cqrs.serialization.query.map'];
        yield 'metadata' => [['metadata' => ['event' => ['map' => [$typo => 'app.metadata']]]], 'somework_cqrs.metadata.event.map'];
        yield 'dispatch mode' => [['dispatch_modes' => ['command' => ['map' => [$typo => 'sync']]]], 'somework_cqrs.dispatch_modes.command.map'];
        yield 'transport' => [['transports' => ['command' => ['map' => [$typo => 'async']]]], 'somework_cqrs.transports.command.map'];
        yield 'dispatch after current bus' => [['async' => ['dispatch_after_current_bus' => ['event' => ['map' => [$typo => false]]]]], 'somework_cqrs.async.dispatch_after_current_bus.event.map'];
        yield 'rate limiter' => [['rate_limiting' => ['query' => ['map' => [$typo => 'limiter']]]], 'somework_cqrs.rate_limiting.query.map'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('unknownMapKeys')]
    public function test_rejects_map_keys_that_are_not_classes_or_interfaces(array $config, string $path): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('Invalid configuration for path "%s": "App\\Command\\CreateTaks" is not an existing class or interface', $path));

        $this->processConfiguration($config);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidServiceIds(): iterable
    {
        yield 'empty default bus' => [['default_bus' => '']];
        yield 'empty command bus' => [['buses' => ['command' => ' ']]];
        yield 'boolean naming strategy' => [['naming' => ['default' => true]]];
        yield 'empty retry policy' => [['retry_policies' => ['command' => ['default' => '']]]];
        yield 'null retry map entry' => [['retry_policies' => ['command' => ['map' => [CreateTaskCommand::class => null]]]]];
        yield 'empty serializer' => [['serialization' => ['default' => '']]];
        yield 'integer metadata provider' => [['metadata' => ['command' => ['default' => 1]]]];
        yield 'empty transport name' => [['transports' => ['command' => ['default' => ['']]]]];
        yield 'empty rate limiter name' => [['rate_limiting' => ['command' => ['map' => [CreateTaskCommand::class => '']]]]];
        yield 'empty causation bus' => [['causation_id' => ['buses' => ['']]]];
        yield 'boolean outbox serializer' => [['outbox' => ['serializer' => true]]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidServiceIds')]
    public function test_rejects_empty_or_non_string_service_ids(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfiguration($config);
    }

    public function test_nullable_service_ids_accept_null(): void
    {
        $config = $this->processConfiguration([
            'default_bus' => null,
            'buses' => ['command_async' => null],
            'naming' => ['command' => null],
            'serialization' => ['command' => ['default' => null]],
        ]);

        self::assertNull($config['default_bus']);
        self::assertNull($config['serialization']['command']['default']);
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
