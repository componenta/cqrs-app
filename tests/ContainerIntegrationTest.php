<?php

declare(strict_types=1);

use Componenta\Config\ConfigLoader;
use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\Config\Environment;
use Componenta\CQRS\App\ConfigProvider as CqrsAppConfigProvider;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\Command\Locator\CommandHandlerLocator;
use Componenta\CQRS\Command\Locator\CommandHandlerLocatorInterface;
use Componenta\CQRS\Command\Locator\CommandListenersLocator;
use Componenta\CQRS\Command\Locator\CommandListenersLocatorInterface;
use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\ConfigProvider as CqrsConfigProvider;
use Componenta\CQRS\Map\CompositeCqrsMapProvider;
use Componenta\CQRS\Map\CqrsMap;
use Componenta\CQRS\Map\CqrsMapProviderInterface;
use Componenta\CQRS\Query\Locator\QueryHandlerLocator;
use Componenta\CQRS\Query\Locator\QueryHandlerLocatorInterface;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\Tokenizer\ClassInfo;

final readonly class CqrsAppIntegrationLocator implements CommandHandlerLocatorInterface
{
    public function locateFor(object $command): callable
    {
        return static fn(object $resolved): object => $resolved;
    }
}

final readonly class CqrsAppIntegrationLocatorDecorator implements CommandHandlerLocatorInterface
{
    public function __construct(public CommandHandlerLocatorInterface $inner) {}

    public function locateFor(object $command): callable
    {
        return $this->inner->locateFor($command);
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class CqrsAppIntegrationMetadata
{
    public function __construct(public string $value) {}
}

#[CqrsAppIntegrationMetadata('discovered')]
final readonly class CqrsAppIntegrationMetadataCommand {}

/**
 * @param list<callable(): array> $providers
 */
function buildCqrsAppIntegrationContainer(array $providers): Container
{
    $config = ConfigLoader::load(
        new Environment(['APP_ENV' => 'development']),
        ...$providers,
    );

    return ContainerBuilder::configure($config)->build();
}

it('builds one effective map provider shared by every core locator', function (): void {
    $container = buildCqrsAppIntegrationContainer([
        new CqrsConfigProvider(),
        new CqrsAppConfigProvider(),
    ]);
    $container->get(CqrsDiscoveryIndex::class)->finalize();

    expect($container->get(CommandHandlerLocatorInterface::class))
        ->toBeInstanceOf(CommandHandlerLocator::class)
        ->and($container->get(QueryHandlerLocatorInterface::class))
        ->toBeInstanceOf(QueryHandlerLocator::class)
        ->and($container->get(CommandListenersLocatorInterface::class))
        ->toBeInstanceOf(CommandListenersLocator::class)
        ->and($container->get(CqrsMapProviderInterface::class))
        ->toBeInstanceOf(CompositeCqrsMapProvider::class)
        ->and($container->get(CqrsMapProviderInterface::class))
        ->toBe($container->get(CqrsMapProviderInterface::class));
});

it('exposes discovered metadata through the same provider used by core runtime services', function (): void {
    $mapProvider = new class extends BaseConfigProvider {
        protected function getConfig(): array
        {
            return [
                ConfigKey::COMMAND_METADATA_ATTRIBUTES => [CqrsAppIntegrationMetadata::class],
                ConfigKey::CQRS_MAP => [
                    'version' => CqrsMap::VERSION,
                    'commands' => [
                        'handlers' => [
                            CqrsAppIntegrationMetadataCommand::class => [
                                'service' => 'metadata.command.handler',
                                'method' => '__invoke',
                            ],
                        ],
                    ],
                ],
            ];
        }
    };
    $container = buildCqrsAppIntegrationContainer([
        new CqrsConfigProvider(),
        new CqrsAppConfigProvider(),
        $mapProvider,
    ]);
    $index = $container->get(CqrsDiscoveryIndex::class);
    $index->handle(new ClassInfo(CqrsAppIntegrationMetadataCommand::class));
    $index->finalize();

    $metadata = $container
        ->get(CommandMetadataProviderInterface::class)
        ->get(CqrsAppIntegrationMetadataCommand::class, CqrsAppIntegrationMetadata::class);

    expect($metadata)->toBeInstanceOf(CqrsAppIntegrationMetadata::class)
        ->and($metadata->value)->toBe('discovered')
        ->and($container->get(CqrsMapProviderInterface::class)->map()
            ->commandMetadata(
                CqrsAppIntegrationMetadataCommand::class,
                CqrsAppIntegrationMetadata::class,
            ))->not->toBeNull();
});

it('lets a later provider select a custom locator implementation', function (): void {
    $customProvider = new class extends BaseConfigProvider {
        protected function getFactories(): array
        {
            return [
                CommandHandlerLocatorInterface::class
                    => static fn(): CommandHandlerLocatorInterface => new CqrsAppIntegrationLocator(),
            ];
        }
    };

    $container = buildCqrsAppIntegrationContainer([
        new CqrsConfigProvider(),
        new CqrsAppConfigProvider(),
        $customProvider,
    ]);

    expect($container->get(CommandHandlerLocatorInterface::class))
        ->toBeInstanceOf(CqrsAppIntegrationLocator::class);
});

it('applies delegators to the locator implementation selected by the last provider', function (): void {
    $customProvider = new class extends BaseConfigProvider {
        protected function getFactories(): array
        {
            return [
                CommandHandlerLocatorInterface::class
                    => static fn(): CommandHandlerLocatorInterface => new CqrsAppIntegrationLocator(),
            ];
        }

        protected function getDelegators(): array
        {
            return [
                CommandHandlerLocatorInterface::class => [
                    static fn(CommandHandlerLocatorInterface $inner): CommandHandlerLocatorInterface
                        => new CqrsAppIntegrationLocatorDecorator($inner),
                ],
            ];
        }
    };

    $container = buildCqrsAppIntegrationContainer([
        new CqrsConfigProvider(),
        new CqrsAppConfigProvider(),
        $customProvider,
    ]);

    $locator = $container->get(CommandHandlerLocatorInterface::class);

    expect($locator)->toBeInstanceOf(CqrsAppIntegrationLocatorDecorator::class)
        ->and($locator->inner)->toBeInstanceOf(CqrsAppIntegrationLocator::class);
});
