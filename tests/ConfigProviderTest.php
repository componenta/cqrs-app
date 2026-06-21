<?php

declare(strict_types=1);

use Componenta\ClassFinder\Compile\ConfigKey as CompileConfigKey;
use Componenta\ClassFinder\ConfigKey as ClassFinderConfigKey;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\Config\ConfigKey as DependencyConfigKey;
use Componenta\CQRS\App\Command\Factory\AttributeCommandHandlerLocatorFactory;
use Componenta\CQRS\App\Command\Factory\AttributeCommandListenersLocatorFactory;
use Componenta\CQRS\App\Command\Factory\CommandHandlerLocatorFactory;
use Componenta\CQRS\App\Command\Factory\CommandListenersLocatorFactory;
use Componenta\CQRS\App\Command\Locator\AttributeCommandHandlerLocator;
use Componenta\CQRS\App\Command\Locator\AttributeCommandListenersLocator;
use Componenta\CQRS\App\Compile\CommandAttributeMapContributor;
use Componenta\CQRS\App\Compile\CommandAttributeMapCompiler;
use Componenta\CQRS\App\Compile\CommandHandlerMapCompiler;
use Componenta\CQRS\App\Compile\CommandListenersMapCompiler;
use Componenta\CQRS\App\Compile\QueryHandlerMapCompiler;
use Componenta\CQRS\App\ConfigProvider;
use Componenta\CQRS\App\Query\Factory\AttributeHandlerLocatorFactory;
use Componenta\CQRS\App\Query\Factory\QueryHandlerLocatorFactory;
use Componenta\CQRS\App\Query\Locator\AttributeQueryHandlerLocator;
use Componenta\CQRS\ConfigKey as CqrsConfigKey;
use Componenta\CQRS\Command\Locator\CommandHandlerLocatorInterface;
use Componenta\CQRS\Command\Locator\CommandListenersLocatorInterface;
use Componenta\CQRS\Query\Locator\QueryHandlerLocatorInterface;
use Psr\Container\ContainerInterface;

describe('CQRS app ConfigProvider', function () {
    it('registers CQRS listener compilers without modifying core CQRS config', function () {
        $config = (new ConfigProvider())();

        expect($config[CompileConfigKey::LISTENER_COMPILERS])->toBe([
            QueryHandlerMapCompiler::class,
            CommandHandlerMapCompiler::class,
            CommandListenersMapCompiler::class,
        ])->and($config[ClassFinderConfigKey::LISTENERS])->toBe([
            AttributeQueryHandlerLocator::class,
            AttributeCommandHandlerLocator::class,
            AttributeCommandListenersLocator::class,
        ])->and($config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::INVOKABLES])->toBe([
            CommandAttributeMapContributor::class,
            CommandAttributeMapCompiler::class,
            QueryHandlerMapCompiler::class,
            CommandHandlerMapCompiler::class,
            CommandListenersMapCompiler::class,
        ])->and($config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::AUTOWIRES] ?? [])->toBe([])->and($config[AppConfigKey::COMPILE_CACHE_CONTRIBUTORS])->toBe([
            CommandAttributeMapContributor::class,
        ]);
    });

    it('registers stable locator factories and attribute locators for discovery', function () {
        $attributeFactories = [
            AttributeCommandHandlerLocator::class => AttributeCommandHandlerLocatorFactory::class,
            AttributeCommandListenersLocator::class => AttributeCommandListenersLocatorFactory::class,
            AttributeQueryHandlerLocator::class => AttributeHandlerLocatorFactory::class,
        ];
        $config = (new ConfigProvider())();

        expect($config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::FACTORIES])->toBe($attributeFactories + [
            QueryHandlerLocatorInterface::class => QueryHandlerLocatorFactory::class,
            CommandHandlerLocatorInterface::class => CommandHandlerLocatorFactory::class,
            CommandListenersLocatorInterface::class => CommandListenersLocatorFactory::class,
        ])->and($config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::ALIASES] ?? [])->toBe([]);
    });

    it('uses the compiled query handler map when the attribute locator is not restored', function () {
        $query = new class {};
        $handler = static fn (object $query): string => $query::class;
        $config = new Config([
            CqrsConfigKey::QUERY_HANDLER_MAP => [
                $query::class => $handler,
            ],
        ]);

        $container = new class ($config) implements ContainerInterface {
            /** @var array<string, mixed> */
            public array $entries;

            public function __construct(Config $config)
            {
                $this->entries = [
                    CqrsConfigKey::CONFIG => $config,
                ];
            }

            public function get(string $id): mixed
            {
                return $this->entries[$id] ?? throw new \RuntimeException($id);
            }

            public function has(string $id): bool
            {
                return isset($this->entries[$id]);
            }
        };
        $container->entries[AttributeQueryHandlerLocator::class] = new AttributeQueryHandlerLocator($container);

        $locator = (new QueryHandlerLocatorFactory())($container);

        expect(($locator->locateFor($query))($query))->toBe($query::class);
    });

    it('uses the compiled command handler map when the attribute locator is not restored', function () {
        $command = new class {};
        $handler = static fn (object $command): string => $command::class;
        $config = new Config([
            CqrsConfigKey::COMMAND_HANDLER_MAP => [
                $command::class => $handler,
            ],
        ]);

        $container = new class ($config) implements ContainerInterface {
            /** @var array<string, mixed> */
            public array $entries;

            public function __construct(Config $config)
            {
                $this->entries = [
                    CqrsConfigKey::CONFIG => $config,
                ];
            }

            public function get(string $id): mixed
            {
                return $this->entries[$id] ?? throw new \RuntimeException($id);
            }

            public function has(string $id): bool
            {
                return isset($this->entries[$id]);
            }
        };
        $container->entries[AttributeCommandHandlerLocator::class] = new AttributeCommandHandlerLocator($container);

        $locator = (new CommandHandlerLocatorFactory())($container);

        expect(($locator->locateFor($command))($command))->toBe($command::class);
    });

    it('uses plain runtime locators in production without resolving attribute locator services', function () {
        $query = new class {};
        $command = new class {};
        $queryHandler = static fn (object $query): string => $query::class;
        $commandHandler = static fn (object $command): string => $command::class;
        $config = new Config([
            CqrsConfigKey::QUERY_HANDLER_MAP => [
                $query::class => $queryHandler,
            ],
            CqrsConfigKey::COMMAND_HANDLER_MAP => [
                $command::class => $commandHandler,
            ],
        ], new Environment(['APP_ENV' => 'production']));

        $container = new class ($config) implements ContainerInterface {
            public function __construct(private readonly Config $config) {}

            public function get(string $id): mixed
            {
                return match ($id) {
                    CqrsConfigKey::CONFIG => $this->config,
                    default => throw new \RuntimeException($id),
                };
            }

            public function has(string $id): bool
            {
                return $id === CqrsConfigKey::CONFIG;
            }
        };

        $queryLocator = (new QueryHandlerLocatorFactory())($container);
        $commandLocator = (new CommandHandlerLocatorFactory())($container);

        expect(($queryLocator->locateFor($query))($query))->toBe($query::class)
            ->and(($commandLocator->locateFor($command))($command))->toBe($command::class);
    });
});


