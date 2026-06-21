<?php

namespace Componenta\CQRS\App\Command\Factory;

use Componenta\CQRS\App\Command\Locator\AttributeCommandListenersLocator;
use Componenta\CQRS\Command\Resolver\CommandNameResolverInterface;
use Psr\Container\ContainerInterface;

final class AttributeCommandListenersLocatorFactory
{
    public function __invoke(ContainerInterface $container): AttributeCommandListenersLocator
    {
        return new AttributeCommandListenersLocator($container, $container->has(CommandNameResolverInterface::class)
            ? $container->get(CommandNameResolverInterface::class) : null);
    }
}
