<?php

declare(strict_types=1);

namespace Componenta\CQRS\App;

use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\ClassFinder\Compile\ConfigKey as CompileConfigKey;
use Componenta\ClassFinder\ConfigKey as ClassFinderConfigKey;
use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\CQRS\App\Command\Factory\CommandHandlerLocatorFactory;
use Componenta\CQRS\App\Command\Factory\CommandListenersLocatorFactory;
use Componenta\CQRS\App\Compile\CqrsMapCompiler;
use Componenta\CQRS\App\Compile\Factory\CqrsMapCompilerFactory;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\App\Discovery\Factory\CqrsDiscoveryIndexFactory;
use Componenta\CQRS\App\Map\ApplicationCqrsMapProvider;
use Componenta\CQRS\App\Map\Factory\ApplicationCqrsMapProviderFactory;
use Componenta\CQRS\App\Query\Factory\QueryHandlerLocatorFactory;
use Componenta\CQRS\Command\Locator\CommandHandlerLocatorInterface;
use Componenta\CQRS\Command\Locator\CommandListenersLocatorInterface;
use Componenta\CQRS\Query\Locator\QueryHandlerLocatorInterface;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getFactories(): array
    {
        return [
            CqrsMapCompiler::class => CqrsMapCompilerFactory::class,
            CqrsDiscoveryIndex::class => CqrsDiscoveryIndexFactory::class,
            ApplicationCqrsMapProvider::class => ApplicationCqrsMapProviderFactory::class,
            QueryHandlerLocatorInterface::class => QueryHandlerLocatorFactory::class,
            CommandHandlerLocatorInterface::class => CommandHandlerLocatorFactory::class,
            CommandListenersLocatorInterface::class => CommandListenersLocatorFactory::class,
        ];
    }

    /**
     * @return array<string, list<class-string>>
     */
    protected function getConfig(): array
    {
        return [
            CompileConfigKey::LISTENER_COMPILERS => [CqrsMapCompiler::class],
            ClassFinderConfigKey::LISTENERS => [CqrsDiscoveryIndex::class],
            AppConfigKey::AUTOWIRE_ENTRY_CONTRIBUTORS => [CqrsDiscoveryIndex::class],
        ];
    }
}

