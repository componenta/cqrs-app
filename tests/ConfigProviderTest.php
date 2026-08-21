<?php

declare(strict_types=1);

use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\ClassFinder\Compile\ConfigKey as CompileConfigKey;
use Componenta\ClassFinder\ConfigKey as ClassFinderConfigKey;
use Componenta\Config\ConfigKey as DependencyConfigKey;
use Componenta\CQRS\App\Compile\CqrsMapAutowireEntryContributor;
use Componenta\CQRS\App\Compile\CqrsMapCompiler;
use Componenta\CQRS\App\Compile\Factory\CqrsMapCompilerFactory;
use Componenta\CQRS\App\ConfigProvider;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\App\Discovery\Factory\CqrsDiscoveryIndexFactory;
use Componenta\CQRS\App\Map\Factory\ApplicationCqrsMapProviderFactory;
use Componenta\CQRS\Map\CqrsMapProviderInterface;

it('rebinds the shared CQRS map provider and registers discovery compilation', function (): void {
    $config = (new ConfigProvider())();
    $dependencies = $config[DependencyConfigKey::DEPENDENCIES];

    expect($config[CompileConfigKey::LISTENER_COMPILERS])
        ->toBe([CqrsMapCompiler::class])
        ->and($config[ClassFinderConfigKey::LISTENERS])
        ->toBe([CqrsDiscoveryIndex::class])
        ->and($config[AppConfigKey::AUTOWIRE_ENTRY_CONTRIBUTORS])
        ->toBe([CqrsMapAutowireEntryContributor::class])
        ->and($dependencies[DependencyConfigKey::INVOKABLES] ?? [])
        ->toBe([])
        ->and($dependencies[DependencyConfigKey::FACTORIES])
        ->toBe([
            CqrsMapCompiler::class => CqrsMapCompilerFactory::class,
            CqrsDiscoveryIndex::class => CqrsDiscoveryIndexFactory::class,
            CqrsMapProviderInterface::class => ApplicationCqrsMapProviderFactory::class,
        ])
        ->and($dependencies[DependencyConfigKey::ALIASES] ?? [])->toBe([]);
});
