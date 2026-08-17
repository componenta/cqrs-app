<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\CQRS\App\ConfigKey as AppConfigKey;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\App\Discovery\Factory\CqrsDiscoveryIndexFactory;
use Componenta\CQRS\App\Map\Factory\ApplicationCqrsMapProviderFactory;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Map\CompositeCqrsMapProvider;
use Componenta\CQRS\Map\CqrsMap;
use Componenta\CQRS\Map\CqrsMapProviderInterface;
use Componenta\Tokenizer\ClassInfo;
use Psr\Container\ContainerInterface;

final readonly class ApplicationMapTestCommand {}

#[AsCommandHandler]
final readonly class ApplicationMapTestHandler
{
    public function __invoke(ApplicationMapTestCommand $command): void {}
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ApplicationMapTestMetadata
{
    public function __construct(public string $value) {}
}

#[ApplicationMapTestMetadata('discovered')]
final readonly class ApplicationMapTestMetadataCommand {}

final class ApplicationMapTestContainer implements ContainerInterface
{
    /** @var array<string, int> */
    public array $gets = [];

    /** @param array<string, mixed> $entries */
    public function __construct(private readonly array $entries) {}

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

/**
 * @param array<string, mixed> $entries
 * @return array{CqrsMapProviderInterface, ApplicationMapTestContainer}
 */
function applicationMapTestProvider(Config $config, array $entries = []): array
{
    $container = new ApplicationMapTestContainer($entries);
    $provider = (new ApplicationCqrsMapProviderFactory())(
        new ContainerValue($container, $config),
    );

    return [$provider, $container];
}

it('composes configured and discovered maps in development', function (?string $environment): void {
    $config = new Config([
        ConfigKey::CQRS_MAP => [
            'version' => CqrsMap::VERSION,
            'commands' => [
                'known' => ['configured.command' => true],
            ],
        ],
    ], $environment === null ? null : new Environment(['APP_ENV' => $environment]));
    [$provider] = applicationMapTestProvider($config, [
        CqrsDiscoveryIndex::class => applicationMapTestIndex(),
    ]);

    expect($provider)->toBeInstanceOf(CompositeCqrsMapProvider::class)
        ->and($provider->map()->isKnownCommand('configured.command'))->toBeTrue()
        ->and($provider->map()->commandHandler(ApplicationMapTestCommand::class)?->service)
        ->toBe(ApplicationMapTestHandler::class);
})->with([
    'missing environment defaults to development' => [null],
    'development' => ['development'],
]);

it('uses only the compiled configured map outside development', function (string $environment): void {
    $config = new Config([
        ConfigKey::CQRS_MAP => ['version' => CqrsMap::VERSION],
    ], new Environment(['APP_ENV' => $environment]));
    [$provider, $container] = applicationMapTestProvider($config);

    expect($provider)->not->toBeInstanceOf(CompositeCqrsMapProvider::class)
        ->and($provider->map()->toArray())->toBe(['version' => CqrsMap::VERSION])
        ->and($container->gets)->not->toHaveKey(CqrsDiscoveryIndex::class);
})->with([
    'production' => ['production'],
    'staging' => ['staging'],
    'test' => ['test'],
]);

it('allows disabling development discovery explicitly', function (): void {
    [$provider, $container] = applicationMapTestProvider(new Config([
        AppConfigKey::DISCOVERY_ENABLED => false,
    ]));

    expect($provider->map()->commandHandler(ApplicationMapTestCommand::class))->toBeNull()
        ->and($container->gets)->not->toHaveKey(CqrsDiscoveryIndex::class);
});

it('rejects explicitly enabling live discovery outside development', function (string $environment): void {
    $config = new Config([
        AppConfigKey::DISCOVERY_ENABLED => true,
        ConfigKey::CQRS_MAP => ['version' => CqrsMap::VERSION],
    ], new Environment(['APP_ENV' => $environment]));

    expect(fn() => applicationMapTestProvider($config, [
        CqrsDiscoveryIndex::class => applicationMapTestIndex(),
    ]))->toThrow(InvalidArgumentException::class, 'only in development');
})->with([
    'production' => ['production'],
    'staging' => ['staging'],
    'test' => ['test'],
]);

it('rejects a non-bool discovery flag', function (): void {
    expect(fn() => applicationMapTestProvider(new Config([
        AppConfigKey::DISCOVERY_ENABLED => 'yes',
    ])))->toThrow(InvalidArgumentException::class, 'must be bool');
});

it('validates and deduplicates configured generic metadata attributes', function (): void {
    $config = new Config([
        ConfigKey::COMMAND_METADATA_ATTRIBUTES => [
            ApplicationMapTestMetadata::class,
            ApplicationMapTestMetadata::class,
        ],
    ]);
    $container = new ApplicationMapTestContainer([
        ConfigKey::CONFIG => $config,
    ]);
    $index = (new CqrsDiscoveryIndexFactory())($container);
    $index->handle(new ClassInfo(ApplicationMapTestMetadataCommand::class));
    $index->finalize();

    expect($index->map()->toArray()['commands']['metadata'])->toBe([
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
