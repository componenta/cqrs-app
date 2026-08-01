<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Compile;

use Componenta\ClassFinder\Compile\CompileResult;
use Componenta\ClassFinder\Compile\ListenerCompilerInterface;
use Componenta\CQRS\App\Command\Locator\AttributeCommandHandlerLocator;
use Componenta\CQRS\ConfigKey;

/**
 * Serialises {@see AttributeCommandHandlerLocator}'s populated map into the
 * same config key the Plain command-handler locator consumes in prod.
 */
final class CommandHandlerMapCompiler implements ListenerCompilerInterface
{
    public function supports(object $listener): bool
    {
        return $listener instanceof AttributeCommandHandlerLocator;
    }

    public function compile(object $listener, string $cacheDir): CompileResult
    {
        /** @var AttributeCommandHandlerLocator $listener */
        return CompileResult::config(ConfigKey::COMMAND_HANDLER_MAP, $listener->toArray());
    }
}
