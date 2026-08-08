<?php

declare(strict_types=1);

use Componenta\ClassFinder\Compile\ConfigKey as CompileConfigKey;
use Componenta\ClassFinder\ConfigKey as ClassFinderConfigKey;
use Componenta\Config\ConfigKey as DependencyConfigKey;
use Componenta\CQRS\App\Command\Factory\CommandHandlerLocatorFactory;
use Componenta\CQRS\App\Command\Factory\CommandListenersLocatorFactory;
use Componenta\CQRS\App\Compile\CqrsMapCompiler;
use Componenta\CQRS\App\ConfigProvider;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\App\Discovery\Factory\CqrsDiscoveryIndexFactory;
use Componenta\CQRS\App\Map\ApplicationCqrsMapProvider;
use Componenta\CQRS\App\Map\Factory\ApplicationCqrsMapProviderFactory;
use Componenta\CQRS\App\Query\Factory\QueryHandlerLocatorFactory;
use Componenta\CQRS\Command\Locator\CommandHandlerLocatorInterface;
use Componenta\CQRS\Command\Locator\CommandListenersLocatorInterface;
use Componenta\CQRS\Query\Locator\QueryHandlerLocatorInterface;

it('registers one discovery listener and one compiler with application locator factories', function (): void {
    $config = (new ConfigProvider())();
    $dependencies = $config[DependencyConfigKey::DEPENDENCIES];

    expect($config[CompileConfigKey::LISTENER_COMPILERS])
        ->toBe([CqrsMapCompiler::class])
        ->and($config[ClassFinderConfigKey::LISTENERS])
        ->toBe([CqrsDiscoveryIndex::class])
        ->and($dependencies[DependencyConfigKey::INVOKABLES])
        ->toBe([CqrsMapCompiler::class])
        ->and($dependencies[DependencyConfigKey::FACTORIES])
        ->toBe([
            CqrsDiscoveryIndex::class => CqrsDiscoveryIndexFactory::class,
            ApplicationCqrsMapProvider::class => ApplicationCqrsMapProviderFactory::class,
            QueryHandlerLocatorInterface::class => QueryHandlerLocatorFactory::class,
            CommandHandlerLocatorInterface::class => CommandHandlerLocatorFactory::class,
            CommandListenersLocatorInterface::class => CommandListenersLocatorFactory::class,
        ])
        ->and($dependencies[DependencyConfigKey::ALIASES] ?? [])->toBe([]);
});


