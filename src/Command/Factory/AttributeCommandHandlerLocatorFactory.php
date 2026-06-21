<?php

namespace Componenta\CQRS\App\Command\Factory;

use Componenta\CQRS\App\Command\Locator\AttributeCommandHandlerLocator;
use Componenta\CQRS\Command\Resolver\CommandNameResolverInterface;
use Psr\Container\ContainerInterface;

final class AttributeCommandHandlerLocatorFactory
{
    public function __invoke(ContainerInterface $container): AttributeCommandHandlerLocator
    {
        return new AttributeCommandHandlerLocator($container, $container->has(CommandNameResolverInterface::class)
            ? $container->get(CommandNameResolverInterface::class) : null);
    }
}
