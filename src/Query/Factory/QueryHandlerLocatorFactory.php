<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Query\Factory;

use Componenta\Config\Config;
use Componenta\CQRS\App\Query\Locator\AttributeQueryHandlerLocator;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Query\Factory\QueryHandlerLocatorFactory as PlainQueryHandlerLocatorFactory;
use Componenta\CQRS\Query\Locator\LocatorCollector;
use Componenta\CQRS\Query\Locator\QueryHandlerLocatorInterface;
use Psr\Container\ContainerInterface;

final class QueryHandlerLocatorFactory
{
    public function __invoke(ContainerInterface $container): QueryHandlerLocatorInterface
    {
        $plain = (new PlainQueryHandlerLocatorFactory())($container);

        if (!$this->isDevelopment($container)) {
            return $plain;
        }

        /** @var AttributeQueryHandlerLocator $attributeLocator */
        $attributeLocator = $container->get(AttributeQueryHandlerLocator::class);

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
