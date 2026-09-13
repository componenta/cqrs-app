<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Build;

use Componenta\Config\ContainerValue;
use Componenta\CQRS\App\ConfigKey;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\ConfigKey as CqrsConfigKey;
use Componenta\CQRS\Internal\RegistrationNormalizer;
use Componenta\Stdlib\PathResolverInterface;
use InvalidArgumentException;

final class CqrsBuilderFactory
{
    public function __invoke(ContainerValue $container): CqrsBuilder
    {
        $config = $container->config;
        $commands = $config->get(CqrsConfigKey::COMMAND_HANDLERS, []);
        $queries = $config->get(CqrsConfigKey::QUERY_HANDLERS, []);
        $listeners = $config->get(CqrsConfigKey::COMMAND_LISTENERS, []);
        RegistrationNormalizer::registrations($commands, $queries, $listeners);
        $path = $config->get(ConfigKey::MAP_FILE, ConfigKey::DEFAULT_MAP_FILE);
        if (!is_string($path) || trim($path) === '') {
            throw new InvalidArgumentException(ConfigKey::MAP_FILE . ' must be a non-empty path.');
        }
        /** @var list<array<string, mixed>> $commands */
        /** @var list<array<string, mixed>> $queries */
        /** @var list<array<string, mixed>> $listeners */
        return new CqrsBuilder(
            $container->get(CqrsDiscoveryIndex::class, CqrsDiscoveryIndex::class),
            $container->get(PathResolverInterface::class, PathResolverInterface::class)->resolve($path),
            $commands,
            $queries,
            $listeners,
        );
    }
}
