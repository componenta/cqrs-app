<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Discovery\Factory;

use Attribute;
use Componenta\Config\Config;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\ConfigKey;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;

final class CqrsDiscoveryIndexFactory
{
    public function __invoke(ContainerInterface $container): CqrsDiscoveryIndex
    {
        $config = $container->get(ConfigKey::CONFIG);

        if (!$config instanceof Config) {
            throw new InvalidArgumentException(sprintf(
                'Container entry "%s" must be a %s instance.',
                ConfigKey::CONFIG,
                Config::class,
            ));
        }

        $attributes = $config->get(ConfigKey::COMMAND_METADATA_ATTRIBUTES, []);

        if (!is_array($attributes) || !array_is_list($attributes)) {
            throw new InvalidArgumentException(
                'CQRS command metadata attributes must be configured as a list of class names.',
            );
        }

        $normalized = [];
        $seen = [];

        foreach ($attributes as $attribute) {
            if (!is_string($attribute) || !class_exists($attribute)) {
                throw new InvalidArgumentException(sprintf(
                    'CQRS command metadata attribute "%s" does not exist.',
                    is_scalar($attribute) ? (string) $attribute : get_debug_type($attribute),
                ));
            }
            if (isset($seen[$attribute])) {
                continue;
            }
            if ((new ReflectionClass($attribute))->getAttributes(Attribute::class) === []) {
                throw new InvalidArgumentException(sprintf(
                    'CQRS command metadata class "%s" is not declared with #[Attribute].',
                    $attribute,
                ));
            }

            $seen[$attribute] = true;
            $normalized[] = $attribute;
        }

        return new CqrsDiscoveryIndex($normalized);
    }
}
