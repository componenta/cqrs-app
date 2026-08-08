<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Map;

use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\Map\CqrsMap;
use Componenta\CQRS\Map\CqrsMapProviderInterface;

final readonly class DiscoveryCqrsMapProvider implements CqrsMapProviderInterface
{
    public function __construct(private CqrsDiscoveryIndex $index)
    {
    }

    public function map(): CqrsMap
    {
        return $this->index->map();
    }
}
