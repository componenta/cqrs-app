<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Command\Factory;

use Componenta\Config\Config;
use Componenta\CQRS\App\Command\Locator\AttributeCommandHandlerLocator;
use Componenta\CQRS\Command\Factory\CommandHandlerLocatorFactory as PlainCommandHandlerLocatorFactory;
use Componenta\CQRS\Command\Locator\CommandHandlerLocatorInterface;
use Componenta\CQRS\Command\Locator\LocatorCollector;
use Componenta\CQRS\ConfigKey;
use Psr\Container\ContainerInterface;

final class CommandHandlerLocatorFactory
{
    public function __invoke(ContainerInterface $container): CommandHandlerLocatorInterface
    {
        $plain = (new PlainCommandHandlerLocatorFactory())($container);

        if (!$this->isDevelopment($container)) {
            return $plain;
        }

        /** @var AttributeCommandHandlerLocator $attributeLocator */
        $attributeLocator = $container->get(AttributeCommandHandlerLocator::class);

        return new LocatorCollector(
            $plain,
            $attributeLocator,
        );
    }

    private function isDevelopment(ContainerInterface $container): bool
    {
        /** @var Config $config */
        $config = $container->get(ConfigKey::CONFIG);

        return $config->environment === null
            || $config->environment->match('APP_ENV', 'development', false);
    }
}
