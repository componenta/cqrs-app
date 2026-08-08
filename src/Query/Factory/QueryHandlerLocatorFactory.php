<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Query\Factory;

use Componenta\CQRS\App\Map\ApplicationCqrsMapProvider;
use Componenta\CQRS\Query\Locator\QueryHandlerLocator;
use Componenta\CQRS\Query\Locator\QueryHandlerLocatorInterface;
use Componenta\CQRS\Query\Resolver\QueryNameResolverInterface;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

final class QueryHandlerLocatorFactory
{
    public function __invoke(ContainerInterface $container): QueryHandlerLocatorInterface
    {
        $mapProvider = $container->get(ApplicationCqrsMapProvider::class);

        if (!$mapProvider instanceof ApplicationCqrsMapProvider) {
            throw new InvalidArgumentException(sprintf(
                'Container entry "%s" must be a %s instance.',
                ApplicationCqrsMapProvider::class,
                ApplicationCqrsMapProvider::class,
            ));
        }

        $resolver = null;

        if ($container->has(QueryNameResolverInterface::class)) {
            $resolver = $container->get(QueryNameResolverInterface::class);

            if (!$resolver instanceof QueryNameResolverInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Container entry "%s" must implement %s.',
                    QueryNameResolverInterface::class,
                    QueryNameResolverInterface::class,
                ));
            }
        }

        return new QueryHandlerLocator(
            $mapProvider,
            $container,
            $resolver,
        );
    }
}
