<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Compile;

use Componenta\ClassFinder\Compile\CompileResult;
use Componenta\ClassFinder\Compile\ListenerCompilerInterface;
use Componenta\Config\ConfigKey as ConfigMergeKey;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Map\CqrsMapProviderInterface;
use InvalidArgumentException;

final readonly class CqrsMapCompiler implements ListenerCompilerInterface
{
    public function __construct(
        private CqrsMapProviderInterface $mapProvider,
        private bool $configuredMapPresent = false,
    ) {
    }

    public function supports(object $listener): bool
    {
        return $listener instanceof CqrsDiscoveryIndex;
    }

    public function compile(object $listener, string $cacheDir): CompileResult
    {
        if (!$listener instanceof CqrsDiscoveryIndex) {
            throw new InvalidArgumentException(sprintf(
                '%s supports only %s.',
                self::class,
                CqrsDiscoveryIndex::class,
            ));
        }

        // Preserve the direct compiler contract: callers cannot compile an
        // index that discovery has not finalized yet.
        $listener->map();

        $artifact = $this->mapProvider->map()->toArray();

        if ($this->configuredMapPresent) {
            $artifact = [
                ConfigMergeKey::OVERRIDE_INDEXES => true,
                ...$artifact,
            ];
        }

        return CompileResult::config(
            ConfigKey::CQRS_MAP,
            $artifact,
        );
    }
}
