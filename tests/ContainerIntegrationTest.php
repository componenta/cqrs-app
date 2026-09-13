<?php

declare(strict_types=1);

use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\CQRS\App\ConfigProvider as CqrsAppConfigProvider;
use Componenta\CQRS\Command\Locator\CommandHandlerLocatorInterface;
use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\ConfigProvider as CqrsConfigProvider;
use Componenta\DI\ContainerFactory;

final readonly class CqrsAppIntegrationLocator implements CommandHandlerLocatorInterface
{
    public function locateFor(object $command): callable
    {
        return static fn (object $resolved): string => 'custom:' . $resolved->value;
    }
}

final readonly class CqrsAppIntegrationLocatorDecorator implements CommandHandlerLocatorInterface
{
    public function __construct(private CommandHandlerLocatorInterface $inner)
    {
    }

    public function locateFor(object $command): callable
    {
        $handler = $this->inner->locateFor($command);
        return static fn (object $resolved): string => 'decorated:' . $handler($resolved);
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final class CqrsAppIntegrationMetadata
{
    public function __construct(public string $value)
    {
    }
}

#[CqrsAppIntegrationMetadata('original')]
final readonly class CqrsAppIntegrationMetadataCommand
{
}

/** @param list<callable(): array> $providers */
function buildCqrsAppIntegrationContainer(array $providers): ContainerValue
{
    $composition = (new ConfigFactory())->create(
        new Environment(['APP_ENV' => 'production']),
        ...$providers,
    );

    return (new ContainerFactory())->create($composition->config, $composition->dependencies);
}

it('reads fresh metadata for an unregistered command without resolving maps or discovery', function (): void {
    $container = buildCqrsAppIntegrationContainer([
        new CqrsConfigProvider(),
        new CqrsAppConfigProvider(),
        static fn (): array => [
            \Componenta\Config\ConfigKey::DEPENDENCIES => [
                \Componenta\Config\ConfigKey::FACTORIES => [
                    \Componenta\CQRS\ConfigKey::MAPS => static fn () => throw new RuntimeException('maps are not available'),
                    \Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex::class
                        => static fn () => throw new RuntimeException('discovery is not available'),
                ],
            ],
        ],
    ]);
    $metadata = $container->get(CommandMetadataProviderInterface::class);
    $first = $metadata->get(CqrsAppIntegrationMetadataCommand::class, CqrsAppIntegrationMetadata::class);
    $first->value = 'changed';
    $second = $metadata->get(new CqrsAppIntegrationMetadataCommand(), CqrsAppIntegrationMetadata::class);

    expect($second->value)->toBe('original')->and($second)->not->toBe($first);
});

it('lets a later provider select a custom locator implementation', function (): void {
    $provider = new class () extends BaseConfigProvider {
        protected function getFactories(): array
        {
            return [
                CommandHandlerLocatorInterface::class => static fn () => new CqrsAppIntegrationLocator(),
            ];
        }
    };
    $container = buildCqrsAppIntegrationContainer([
        new CqrsConfigProvider(),
        new CqrsAppConfigProvider(),
        $provider,
    ]);
    $command = (object) ['value' => 'input'];

    expect($container->get(CommandHandlerLocatorInterface::class)->locateFor($command)($command))
        ->toBe('custom:input');
});

it('applies delegators to the locator selected by the last provider', function (): void {
    $provider = new class () extends BaseConfigProvider {
        protected function getFactories(): array
        {
            return [
                CommandHandlerLocatorInterface::class => static fn () => new CqrsAppIntegrationLocator(),
            ];
        }

        protected function getDelegators(): array
        {
            return [
                CommandHandlerLocatorInterface::class => [
                    static fn (CommandHandlerLocatorInterface $inner) => new CqrsAppIntegrationLocatorDecorator($inner),
                ],
            ];
        }
    };
    $container = buildCqrsAppIntegrationContainer([
        new CqrsConfigProvider(),
        new CqrsAppConfigProvider(),
        $provider,
    ]);
    $command = (object) ['value' => 'input'];

    expect($container->get(CommandHandlerLocatorInterface::class)->locateFor($command)($command))
        ->toBe('decorated:custom:input');
});
