<?php

namespace Componenta\CQRS\App\Query\Factory;

use Componenta\CQRS\App\Query\Locator\AttributeQueryHandlerLocator;
use Componenta\CQRS\Query\Resolver\QueryNameResolverInterface;
use Psr\Container\ContainerInterface;

final class AttributeHandlerLocatorFactory
{
    public function __invoke(ContainerInterface $container): AttributeQueryHandlerLocator
    {
        return new AttributeQueryHandlerLocator(
            $container,
            $container->has(QueryNameResolverInterface::class) ?
                $container->get(QueryNameResolverInterface::class) : null
        );
    }
}
