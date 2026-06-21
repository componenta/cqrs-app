<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Command\Factory;

use Componenta\Config\Config;
use Componenta\CQRS\App\Command\Locator\AttributeCommandListenersLocator;
use Componenta\CQRS\App\Command\Locator\CommandListenersLocatorCollector;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Command\Factory\CommandListenerLocatorFactory as PlainCommandListenersLocatorFactory;
use Componenta\CQRS\Command\Locator\CommandListenersLocatorInterface;
use Psr\Container\ContainerInterface;

final class CommandListenersLocatorFactory
{
    public function __invoke(ContainerInterface $container): CommandListenersLocatorInterface
    {
        $plain = (new PlainCommandListenersLocatorFactory())($container);

        if (!$this->isDevelopment($container)) {
            return $plain;
        }

        /** @var AttributeCommandListenersLocator $attributeLocator */
        $attributeLocator = $container->get(AttributeCommandListenersLocator::class);

        return new CommandListenersLocatorCollector(
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
