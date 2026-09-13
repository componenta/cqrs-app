<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Discovery\Factory;

use Componenta\App\ConfigKey;
use Componenta\ClassFinder\ClassIterator;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ContainerValue;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;

final class CqrsDiscoveryIndexFactory
{
    public function __invoke(ContainerValue $container): CqrsDiscoveryIndex
    {
        return new CqrsDiscoveryIndex($container->has(ConfigKey::DISCOVERY_SOURCE)
            ? $container->get(ConfigKey::DISCOVERY_SOURCE, ClassIteratorInterface::class)
            : new ClassIterator([]));
    }
}
