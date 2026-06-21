<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Compile;

use Componenta\ClassFinder\Compile\CompileResult;
use Componenta\ClassFinder\Compile\ListenerCompilerInterface;
use Componenta\CQRS\App\Command\Locator\AttributeCommandListenersLocator;
use Componenta\CQRS\ConfigKey;

/**
 * Serialises {@see AttributeCommandListenersLocator}'s populated map for the
 * Plain variant to pick up in production.
 */
final class CommandListenersMapCompiler implements ListenerCompilerInterface
{
    public function supports(object $listener): bool
    {
        return $listener instanceof AttributeCommandListenersLocator;
    }

    public function compile(object $listener, string $cacheDir): CompileResult
    {
        /** @var AttributeCommandListenersLocator $listener */
        return CompileResult::config(ConfigKey::COMMAND_LISTENER_MAP, $listener->toArray());
    }
}
