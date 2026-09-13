<?php

declare(strict_types=1);

namespace Componenta\CQRS\App;

use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\CQRS\App\Build\CqrsBuilder;
use Componenta\CQRS\App\Build\CqrsBuilderFactory;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\App\Discovery\Factory\CqrsDiscoveryIndexFactory;
use Componenta\CQRS\App\Factory\CqrsMapsFactory;
use Componenta\CQRS\ConfigKey as CqrsConfigKey;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getFactories(): array
    {
        return [
            CqrsConfigKey::MAPS => CqrsMapsFactory::class,
            CqrsDiscoveryIndex::class => CqrsDiscoveryIndexFactory::class,
            CqrsBuilder::class => CqrsBuilderFactory::class,
        ];
    }

    protected function getConfig(): array
    {
        return [AppConfigKey::BUILDERS => [CqrsBuilder::class]];
    }
}
