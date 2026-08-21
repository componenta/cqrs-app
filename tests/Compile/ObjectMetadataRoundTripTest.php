<?php

declare(strict_types=1);

use Componenta\Config\ConfigLoader;
use Componenta\Config\Environment;
use Componenta\CQRS\App\Compile\CqrsMapCompiler;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Map\CqrsMap;
use Componenta\CQRS\Map\CqrsMapProviderInterface;
use Componenta\Tokenizer\ClassInfo;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ObjectRoundTripMetadata
{
    public function __construct(public ObjectRoundTripValue $value) {}
}

final readonly class ObjectRoundTripValue
{
    public function __construct(
        public int $id,
        public array $options,
    ) {}
}

#[ObjectRoundTripMetadata(new ObjectRoundTripValue(7, ['enabled' => true]))]
final readonly class ObjectRoundTripCommand
{
}

it('round-trips object-valued command metadata through the production config artifact', function (): void {
    // Object-valued metadata equality/export semantics are part of the CQRS v4 line.
    if (!(new ReflectionClass(OperationInterface::class))->hasProperty('createdAt')) {
        $this->markTestSkipped('Object metadata round-trip requires CQRS v4.');
    }

    $index = new CqrsDiscoveryIndex([ObjectRoundTripMetadata::class]);
    $index->handle(new ClassInfo(ObjectRoundTripCommand::class));
    $index->finalize();

    $provider = new readonly class($index) implements CqrsMapProviderInterface {
        public function __construct(private CqrsDiscoveryIndex $index) {}

        public function map(): CqrsMap
        {
            return $this->index->map();
        }
    };
    $compiled = (new CqrsMapCompiler($provider))->compile($index, '')->configValue;
    $cacheFile = tempnam(sys_get_temp_dir(), 'cqrs-object-metadata-');

    if ($cacheFile === false) {
        throw new RuntimeException('Unable to create object metadata cache file.');
    }

    try {
        $config = ConfigLoader::load(
            new Environment(['APP_ENV' => 'production']),
            static fn(): array => [ConfigKey::CQRS_MAP => $compiled],
        );
        ConfigLoader::export($config, $cacheFile);
        $loaded = ConfigLoader::loadFromFile($cacheFile);
        $restored = CqrsMap::fromArray($loaded->get(ConfigKey::CQRS_MAP));
        $descriptor = $restored->commandMetadata(
            ObjectRoundTripCommand::class,
            ObjectRoundTripMetadata::class,
        );

        expect($descriptor)->not->toBeNull()
            ->and($provider->map()->merge($restored)->toArray())
            ->toEqual($restored->toArray())
            ->and($descriptor?->arguments['value'])
            ->toEqual(new ObjectRoundTripValue(7, ['enabled' => true]));
    } finally {
        @unlink($cacheFile);
    }
});
