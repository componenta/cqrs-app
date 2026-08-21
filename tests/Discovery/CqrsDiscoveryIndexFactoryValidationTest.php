<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\CQRS\App\Discovery\Factory\CqrsDiscoveryIndexFactory;
use Componenta\CQRS\ConfigKey;
use Psr\Container\ContainerInterface;

#[Attribute(Attribute::TARGET_METHOD)]
final class FactoryMethodOnlyMetadata
{
}

it('rejects metadata attributes that cannot target classes before discovery starts', function (): void {
    $container = new readonly class implements ContainerInterface {
        public function get(string $id): mixed
        {
            if ($id === ConfigKey::CONFIG) {
                return new Config([
                    ConfigKey::COMMAND_METADATA_ATTRIBUTES => [
                        FactoryMethodOnlyMetadata::class,
                    ],
                ]);
            }

            throw new RuntimeException($id);
        }

        public function has(string $id): bool
        {
            return $id === ConfigKey::CONFIG;
        }
    };

    expect(fn() => (new CqrsDiscoveryIndexFactory())($container))
        ->toThrow(InvalidArgumentException::class, 'must allow class targets');
});
