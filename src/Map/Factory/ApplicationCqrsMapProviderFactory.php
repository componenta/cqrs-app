<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Map\Factory;

use Componenta\Config\ContainerValue;
use Componenta\CQRS\App\ConfigKey as AppConfigKey;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\App\Map\DiscoveryCqrsMapProvider;
use Componenta\CQRS\Map\CompositeCqrsMapProvider;
use Componenta\CQRS\Map\ConfigCqrsMapProvider;
use Componenta\CQRS\Map\CqrsMapProviderInterface;
use InvalidArgumentException;

final class ApplicationCqrsMapProviderFactory
{
    public function __invoke(ContainerValue $container): CqrsMapProviderInterface
    {
        $config = $container->config;
        $environment = $config->environment?->string('APP_ENV', 'development')
            ?? 'development';
        $isDevelopment = $environment === 'development';
        $enabled = $config->get(
            AppConfigKey::DISCOVERY_ENABLED,
            $isDevelopment,
        );

        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('CQRS discovery flag must be bool.');
        }

        if ($enabled && !$isDevelopment) {
            throw new InvalidArgumentException(sprintf(
                'Runtime CQRS discovery may be enabled only in development; "%s" environment requires a compiled CQRS map.',
                $environment,
            ));
        }

        $configured = new ConfigCqrsMapProvider($config);

        if (!$enabled) {
            return $configured;
        }

        $index = $container->get(
            CqrsDiscoveryIndex::class,
            CqrsDiscoveryIndex::class,
        );

        return new CompositeCqrsMapProvider(
            $configured,
            new DiscoveryCqrsMapProvider($index),
        );
    }
}
