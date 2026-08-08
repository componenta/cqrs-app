<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Compile;

use Componenta\ClassFinder\Compile\CompileResult;
use Componenta\ClassFinder\Compile\ListenerCompilerInterface;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\ConfigKey;

final class CqrsMapCompiler implements ListenerCompilerInterface
{
    public function supports(object $listener): bool
    {
        return $listener instanceof CqrsDiscoveryIndex;
    }

    public function compile(object $listener, string $cacheDir): CompileResult
    {
        if (!$listener instanceof CqrsDiscoveryIndex) {
            throw new \InvalidArgumentException(sprintf(
                '%s supports only %s.',
                self::class,
                CqrsDiscoveryIndex::class,
            ));
        }

        return CompileResult::config(
            ConfigKey::CQRS_MAP,
            $listener->map()->toArray(),
        );
    }
}
