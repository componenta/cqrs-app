<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Map\Factory;

use Componenta\Config\Config;
use Componenta\CQRS\App\ConfigKey as AppConfigKey;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\App\Map\ApplicationCqrsMapProvider;
use Componenta\CQRS\App\Map\DiscoveryCqrsMapProvider;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Map\CqrsMapProviderInterface;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

final class ApplicationCqrsMapProviderFactory
{
    public function __invoke(ContainerInterface $container): ApplicationCqrsMapProvider
    {
        $config = $container->get(ConfigKey::CONFIG);
        $base = $container->get(CqrsMapProviderInterface::class);

        if (!$config instanceof Config || !$base instanceof CqrsMapProviderInterface) {
            throw new InvalidArgumentException(
                'CQRS application map provider requires Config and CqrsMapProviderInterface services.',
            );
        }

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

        if (!$enabled) {
            return new ApplicationCqrsMapProvider($base);
        }

        $index = $container->get(CqrsDiscoveryIndex::class);

        if (!$index instanceof CqrsDiscoveryIndex) {
            throw new InvalidArgumentException(sprintf(
                'Container entry "%s" must be a %s instance.',
                CqrsDiscoveryIndex::class,
                CqrsDiscoveryIndex::class,
            ));
        }

        return new ApplicationCqrsMapProvider(
            $base,
            new DiscoveryCqrsMapProvider($index),
        );
    }
}
