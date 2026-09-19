<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Discovery\Factory;

use Componenta\ClassFinder\ClassIterator;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ContainerValue;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;

final class CqrsDiscoveryIndexFactory
{
    public function __invoke(ContainerValue $container): CqrsDiscoveryIndex
    {
        return new CqrsDiscoveryIndex($container->has(ClassIteratorInterface::class)
            ? $container->get(ClassIteratorInterface::class, ClassIteratorInterface::class)
            : new ClassIterator([]));
    }
}
