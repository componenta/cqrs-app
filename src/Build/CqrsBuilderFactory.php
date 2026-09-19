<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Build;

use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\ClassFinder\ClassIterator;
use Componenta\ClassFinder\ClassIteratorInterface;
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
            new CqrsDiscoveryIndex($container->has(AppConfigKey::DISCOVERY_SOURCE)
                ? $container->get(AppConfigKey::DISCOVERY_SOURCE, ClassIteratorInterface::class)
                : new ClassIterator([])),
            $container->get(PathResolverInterface::class, PathResolverInterface::class)->resolve($path),
            $commands,
            $queries,
            $listeners,
        );
    }
}
