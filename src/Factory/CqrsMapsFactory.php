<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Factory;

use Componenta\App\Build\PhpMapFile;
use Componenta\Config\ContainerValue;
use Componenta\CQRS\App\ConfigKey;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\Internal\RegistrationNormalizer;
use Componenta\Stdlib\PathResolverInterface;
use InvalidArgumentException;
use Throwable;

/** @phpstan-import-type Maps from RegistrationNormalizer */
final class CqrsMapsFactory
{
    /** @return Maps */
    public function __invoke(ContainerValue $container): array
    {
        $explicit = RegistrationNormalizer::fromConfig($container->config);
        $path = $container->config->get(ConfigKey::MAP_FILE, ConfigKey::DEFAULT_MAP_FILE);
        if (!is_string($path) || trim($path) === '') {
            throw new InvalidArgumentException(ConfigKey::MAP_FILE . ' must be a non-empty path.');
        }
        $file = $container->get(PathResolverInterface::class, PathResolverInterface::class)->resolve($path);
        $maps = $container->config->environment->bool('APP_DISCOVERY_SOURCE', false)
            ? null
            : self::read($file);
        if ($maps !== null) {
            return $maps;
        }
        $index = $container->get(CqrsDiscoveryIndex::class, CqrsDiscoveryIndex::class);
        return RegistrationNormalizer::merge([
            'command_handlers' => $index->commandHandlers(),
            'query_handlers' => $index->queryHandlers(),
            'command_listeners' => $index->commandListeners(),
        ], $explicit);
    }

    /** @return Maps|null */
    private static function read(string $file): ?array
    {
        $map = PhpMapFile::read($file);
        if ($map === null) {
            return null;
        }

        try {
            return RegistrationNormalizer::maps($map);
        } catch (Throwable) {
            return null;
        }
    }
}
