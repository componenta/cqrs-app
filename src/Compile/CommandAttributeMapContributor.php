<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Compile;

use Componenta\App\Discovery\Compile\CompileCacheContributorInterface;
use Componenta\CQRS\ConfigKey;

final readonly class CommandAttributeMapContributor implements CompileCacheContributorInterface
{
    /**
     * @param list<class-string> $classes
     *
     * @return array<string, mixed>
     */
    public function compile(array $classes): array
    {
        return [
            ConfigKey::COMMAND_ATTRIBUTE_MAP => (new CommandAttributeMapCompiler())->compile($classes),
        ];
    }
}
