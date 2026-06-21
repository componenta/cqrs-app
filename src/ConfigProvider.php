<?php

declare(strict_types=1);

namespace Componenta\CQRS\App;

use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\ClassFinder\ConfigKey as ClassFinderConfigKey;
use Componenta\ClassFinder\Compile\ConfigKey as CompileConfigKey;
use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\CQRS\App\Command\Factory\CommandHandlerLocatorFactory;
use Componenta\CQRS\App\Command\Factory\CommandListenersLocatorFactory;
use Componenta\CQRS\App\Command\Factory\AttributeCommandHandlerLocatorFactory;
use Componenta\CQRS\App\Command\Factory\AttributeCommandListenersLocatorFactory;
use Componenta\CQRS\App\Command\Locator\AttributeCommandHandlerLocator;
use Componenta\CQRS\App\Command\Locator\AttributeCommandListenersLocator;
use Componenta\CQRS\App\Compile\CommandAttributeMapContributor;
use Componenta\CQRS\App\Compile\CommandAttributeMapCompiler;
use Componenta\CQRS\App\Compile\CommandHandlerMapCompiler;
use Componenta\CQRS\App\Compile\CommandListenersMapCompiler;
use Componenta\CQRS\App\Compile\QueryHandlerMapCompiler;
use Componenta\CQRS\App\Query\Factory\AttributeHandlerLocatorFactory;
use Componenta\CQRS\App\Query\Factory\QueryHandlerLocatorFactory;
use Componenta\CQRS\App\Query\Locator\AttributeQueryHandlerLocator;
use Componenta\CQRS\Command\Locator\CommandHandlerLocatorInterface;
use Componenta\CQRS\Command\Locator\CommandListenersLocatorInterface;
use Componenta\CQRS\Query\Locator\QueryHandlerLocatorInterface;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getFactories(): array
    {
        return [
            AttributeCommandHandlerLocator::class => AttributeCommandHandlerLocatorFactory::class,
            AttributeCommandListenersLocator::class => AttributeCommandListenersLocatorFactory::class,
            AttributeQueryHandlerLocator::class => AttributeHandlerLocatorFactory::class,
            QueryHandlerLocatorInterface::class => QueryHandlerLocatorFactory::class,
            CommandHandlerLocatorInterface::class => CommandHandlerLocatorFactory::class,
            CommandListenersLocatorInterface::class => CommandListenersLocatorFactory::class,
        ];
    }

    protected function getAliases(): array
    {
        return [];
    }

    protected function getInvokables(): array
    {
        return [
            CommandAttributeMapContributor::class,
            CommandAttributeMapCompiler::class,
            QueryHandlerMapCompiler::class,
            CommandHandlerMapCompiler::class,
            CommandListenersMapCompiler::class,
        ];
    }


    protected function getConfig(): array
    {
        return [
            CompileConfigKey::LISTENER_COMPILERS => [
                QueryHandlerMapCompiler::class,
                CommandHandlerMapCompiler::class,
                CommandListenersMapCompiler::class,
            ],
            ClassFinderConfigKey::LISTENERS => [
                AttributeQueryHandlerLocator::class,
                AttributeCommandHandlerLocator::class,
                AttributeCommandListenersLocator::class,
            ],
            AppConfigKey::COMPILE_CACHE_CONTRIBUTORS => [
                CommandAttributeMapContributor::class,
            ],
        ];
    }
}

