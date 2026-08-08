<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Map;

use Componenta\CQRS\Map\CqrsMap;
use Componenta\CQRS\Map\CqrsMapProviderInterface;

final class ApplicationCqrsMapProvider implements CqrsMapProviderInterface
{
    private ?CqrsMap $map = null;

    public function __construct(
        private readonly CqrsMapProviderInterface $base,
        private readonly ?CqrsMapProviderInterface $discovery = null,
    ) {
    }

    public function map(): CqrsMap
    {
        if ($this->map !== null) {
            return $this->map;
        }

        return $this->map = $this->discovery === null
            ? $this->base->map()
            : $this->base->map()->merge($this->discovery->map());
    }
}
