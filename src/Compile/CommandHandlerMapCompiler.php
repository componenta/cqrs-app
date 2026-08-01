<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Compile;

use Componenta\ClassFinder\Compile\CompileResult;
use Componenta\ClassFinder\Compile\ListenerCompilerInterface;
use Componenta\CQRS\App\Command\Locator\AttributeCommandHandlerLocator;
use Componenta\CQRS\ConfigKey;
use ReflectionMethod;

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
        return CompileResult::config(
            ConfigKey::COMMAND_HANDLER_MAP,
            $this->withInvocationMode($listener->toArray()),
        );
    }

    /**
     * @param array<string, array{0: class-string, 1: string}> $map
     * @return array<string, array{0: class-string, 1: string, 2: bool}>
     */
    private function withInvocationMode(array $map): array
    {
        foreach ($map as $message => [$handler, $method]) {
            $reflection = new ReflectionMethod($handler, $method);
            $parameters = $reflection->getParameters();
            $map[$message] = [
                $handler,
                $method,
                count($parameters) === 1 && !$parameters[0]->isVariadic(),
            ];
        }

        return $map;
    }
}
