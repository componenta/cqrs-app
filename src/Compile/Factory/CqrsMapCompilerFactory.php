<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Compile\Factory;

use Componenta\CQRS\App\Compile\CqrsMapCompiler;
use Componenta\CQRS\Map\CqrsMapProviderInterface;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

final readonly class CqrsMapCompilerFactory
{
    public function __invoke(ContainerInterface $container): CqrsMapCompiler
    {
        $base = $container->get(CqrsMapProviderInterface::class);

        if (!$base instanceof CqrsMapProviderInterface) {
            throw new InvalidArgumentException(sprintf(
                'Container entry "%s" must implement %s.',
                CqrsMapProviderInterface::class,
                CqrsMapProviderInterface::class,
            ));
        }

        return new CqrsMapCompiler($base);
    }
}
