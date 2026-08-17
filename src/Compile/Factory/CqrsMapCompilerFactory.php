<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Compile\Factory;

use Componenta\Config\ContainerValue;
use Componenta\CQRS\App\Compile\CqrsMapCompiler;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Map\CqrsMapProviderInterface;

final readonly class CqrsMapCompilerFactory
{
    public function __invoke(ContainerValue $container): CqrsMapCompiler
    {
        return new CqrsMapCompiler(
            mapProvider: $container->get(
                CqrsMapProviderInterface::class,
                CqrsMapProviderInterface::class,
            ),
            configuredMapPresent: $container->config->has(ConfigKey::CQRS_MAP),
        );
    }
}
