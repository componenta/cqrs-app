<?php

declare(strict_types=1);

use Componenta\Config\ConfigLoader;
use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\Config\Environment;
use Componenta\CQRS\App\ConfigProvider as CqrsAppConfigProvider;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\App\Map\ApplicationCqrsMapProvider;
use Componenta\CQRS\Command\Locator\CommandHandlerLocator;
use Componenta\CQRS\Command\Locator\CommandHandlerLocatorInterface;
use Componenta\CQRS\Command\Locator\CommandListenersLocator;
use Componenta\CQRS\Command\Locator\CommandListenersLocatorInterface;
use Componenta\CQRS\ConfigProvider as CqrsConfigProvider;
use Componenta\CQRS\Query\Locator\QueryHandlerLocator;
use Componenta\CQRS\Query\Locator\QueryHandlerLocatorInterface;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;

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

it('builds a DI v2 container with three locators sharing one application map', function (): void {
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
        ->and($container->get(ApplicationCqrsMapProvider::class))
        ->toBe($container->get(ApplicationCqrsMapProvider::class));
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
