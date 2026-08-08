<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\CQRS\App\ConfigKey as AppConfigKey;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\App\Discovery\Factory\CqrsDiscoveryIndexFactory;
use Componenta\CQRS\App\Map\Factory\ApplicationCqrsMapProviderFactory;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Map\CqrsMap;
use Componenta\CQRS\Map\CqrsMapProviderInterface;
use Componenta\Tokenizer\ClassInfo;
use Psr\Container\ContainerInterface;

final readonly class ApplicationMapTestCommand
{
}

#[AsCommandHandler]
final readonly class ApplicationMapTestHandler
{
    public function __invoke(ApplicationMapTestCommand $command): void
    {
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ApplicationMapTestMetadata
{
    public function __construct(public string $value)
    {
    }
}

#[ApplicationMapTestMetadata('discovered')]
final readonly class ApplicationMapTestMetadataCommand
{
}

final readonly class ApplicationMapTestBaseProvider implements CqrsMapProviderInterface
{
    public function __construct(private CqrsMap $map = new CqrsMap())
    {
    }

    public function map(): CqrsMap
    {
        return $this->map;
    }
}

final class ApplicationMapTestContainer implements ContainerInterface
{
    /** @var array<string, int> */
    public array $gets = [];

    /** @param array<string, mixed> $entries */
    public function __construct(private readonly array $entries)
    {
    }

    public function get(string $id): mixed
    {
        $this->gets[$id] = ($this->gets[$id] ?? 0) + 1;

        return $this->entries[$id] ?? throw new RuntimeException($id);
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

function applicationMapTestIndex(): CqrsDiscoveryIndex
{
    $index = new CqrsDiscoveryIndex();
    $index->handle(new ClassInfo(ApplicationMapTestHandler::class));
    $index->finalize();

    return $index;
}

it('enables discovery for every non-production environment', function (
    ?string $environment,
): void {
    $config = new Config(
        [],
        $environment === null ? null : new Environment(['APP_ENV' => $environment]),
    );
    $container = new ApplicationMapTestContainer([
        ConfigKey::CONFIG => $config,
        CqrsMapProviderInterface::class => new ApplicationMapTestBaseProvider(),
        CqrsDiscoveryIndex::class => applicationMapTestIndex(),
    ]);

    $provider = (new ApplicationCqrsMapProviderFactory())($container);

    expect($provider->map()->commandHandler(ApplicationMapTestCommand::class)?->service)
        ->toBe(ApplicationMapTestHandler::class);
})->with([
    'missing environment' => [null],
    'development' => ['development'],
    'test' => ['test'],
    'staging' => ['staging'],
]);

it('does not resolve discovery services in production', function (): void {
    $container = new ApplicationMapTestContainer([
        ConfigKey::CONFIG => new Config(
            [],
            new Environment(['APP_ENV' => 'production']),
        ),
        CqrsMapProviderInterface::class => new ApplicationMapTestBaseProvider(),
    ]);

    $provider = (new ApplicationCqrsMapProviderFactory())($container);

    expect($provider->map()->toArray())->toBe(['version' => CqrsMap::VERSION])
        ->and($container->gets)->not->toHaveKey(CqrsDiscoveryIndex::class);
});

it('allows an explicit bool flag to override the environment default', function (): void {
    $disabledContainer = new ApplicationMapTestContainer([
        ConfigKey::CONFIG => new Config([
            AppConfigKey::DISCOVERY_ENABLED => false,
        ]),
        CqrsMapProviderInterface::class => new ApplicationMapTestBaseProvider(),
    ]);
    $enabledContainer = new ApplicationMapTestContainer([
        ConfigKey::CONFIG => new Config([
            AppConfigKey::DISCOVERY_ENABLED => true,
        ], new Environment(['APP_ENV' => 'production'])),
        CqrsMapProviderInterface::class => new ApplicationMapTestBaseProvider(),
        CqrsDiscoveryIndex::class => applicationMapTestIndex(),
    ]);

    $disabled = (new ApplicationCqrsMapProviderFactory())($disabledContainer);
    $enabled = (new ApplicationCqrsMapProviderFactory())($enabledContainer);

    expect($disabled->map()->commandHandler(ApplicationMapTestCommand::class))->toBeNull()
        ->and($enabled->map()->commandHandler(ApplicationMapTestCommand::class))
        ->not->toBeNull();
});

it('rejects a non-bool discovery flag', function (): void {
    $container = new ApplicationMapTestContainer([
        ConfigKey::CONFIG => new Config([
            AppConfigKey::DISCOVERY_ENABLED => 'yes',
        ]),
        CqrsMapProviderInterface::class => new ApplicationMapTestBaseProvider(),
    ]);

    expect(fn() => (new ApplicationCqrsMapProviderFactory())($container))
        ->toThrow(InvalidArgumentException::class, 'must be bool');
});

it('validates and deduplicates configured generic metadata attributes', function (): void {
    $container = new ApplicationMapTestContainer([
        ConfigKey::CONFIG => new Config([
            ConfigKey::COMMAND_METADATA_ATTRIBUTES => [
                ApplicationMapTestMetadata::class,
                ApplicationMapTestMetadata::class,
            ],
        ]),
    ]);
    $index = (new CqrsDiscoveryIndexFactory())($container);
    $index->handle(new ClassInfo(ApplicationMapTestMetadataCommand::class));
    $index->finalize();

    expect($index->map()->toArray()['commands']['metadata'])
        ->toBe([
            ApplicationMapTestMetadataCommand::class => [
                ApplicationMapTestMetadata::class => [
                    'arguments' => [0 => 'discovered'],
                ],
            ],
        ]);
});

it('rejects configured metadata classes that are not PHP attributes', function (): void {
    $container = new ApplicationMapTestContainer([
        ConfigKey::CONFIG => new Config([
            ConfigKey::COMMAND_METADATA_ATTRIBUTES => [stdClass::class],
        ]),
    ]);

    expect(fn() => (new CqrsDiscoveryIndexFactory())($container))
        ->toThrow(InvalidArgumentException::class, 'not declared with #[Attribute]');
});
