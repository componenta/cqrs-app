<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Command\Factory;

use Componenta\CQRS\App\Map\ApplicationCqrsMapProvider;
use Componenta\CQRS\Command\Locator\CommandListenersLocator;
use Componenta\CQRS\Command\Locator\CommandListenersLocatorInterface;
use Componenta\CQRS\Command\Resolver\CommandNameResolverInterface;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

final class CommandListenersLocatorFactory
{
    public function __invoke(ContainerInterface $container): CommandListenersLocatorInterface
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

        if ($container->has(CommandNameResolverInterface::class)) {
            $resolver = $container->get(CommandNameResolverInterface::class);

            if (!$resolver instanceof CommandNameResolverInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Container entry "%s" must implement %s.',
                    CommandNameResolverInterface::class,
                    CommandNameResolverInterface::class,
                ));
            }
        }

        return new CommandListenersLocator(
            $mapProvider,
            $container,
            $resolver,
        );
    }
}
