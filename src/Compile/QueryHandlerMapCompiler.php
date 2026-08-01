<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Compile;

use Componenta\ClassFinder\Compile\CompileResult;
use Componenta\ClassFinder\Compile\ListenerCompilerInterface;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\App\Query\Locator\AttributeQueryHandlerLocator;

/**
 * Serialises {@see AttributeQueryHandlerLocator}'s populated map into the
 * same config key that `QueryHandlerLocatorFactory` reads when building the
 * production Plain variant.
 */
final class QueryHandlerMapCompiler implements ListenerCompilerInterface
{
    public function supports(object $listener): bool
    {
        return $listener instanceof AttributeQueryHandlerLocator;
    }

    public function compile(object $listener, string $cacheDir): CompileResult
    {
        /** @var AttributeQueryHandlerLocator $listener */
        return CompileResult::config(ConfigKey::QUERY_HANDLER_MAP, $listener->toArray());
    }
}
