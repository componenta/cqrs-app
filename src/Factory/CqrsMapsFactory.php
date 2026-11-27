<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Factory;

use Componenta\App\Build\PreparedBuild;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\CQRS\App\Build\CqrsBuilder;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\Internal\RegistrationNormalizer;
use Throwable;

/** @phpstan-import-type Maps from RegistrationNormalizer */
final class CqrsMapsFactory
{
    /** @return Maps */
    public function __invoke(ContainerValue $container): array
    {
        $explicit = RegistrationNormalizer::fromConfig($container->config);
        $maps = $container->config->environment->bool('APP_DISCOVERY_SOURCE', false)
            || !$container->has(AppConfigKey::PREPARED_BUILD)
            ? null
            : self::read($container->get(AppConfigKey::PREPARED_BUILD, PreparedBuild::class));
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
    private static function read(PreparedBuild $prepared): ?array
    {
        $map = $prepared->read(CqrsBuilder::ARTIFACT_ID, CqrsBuilder::SCHEMA_VERSION);
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
