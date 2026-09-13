<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Factory;

use Componenta\Config\ContainerValue;
use Componenta\CQRS\App\ConfigKey;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\Internal\RegistrationNormalizer;
use Componenta\Stdlib\PathResolverInterface;
use ErrorException;
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
        $maps = self::read($file);
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
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $maps = (static fn (string $path): mixed => require $path)($file);
            return RegistrationNormalizer::maps($maps);
        } catch (Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }
}
