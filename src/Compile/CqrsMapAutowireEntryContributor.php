<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Compile;

use Componenta\CQRS\Map\CqrsMapProviderInterface;
use Componenta\DI\Compile\Autowire\AutowireEntry;
use Componenta\DI\Compile\Autowire\AutowireEntryContributorInterface;

/**
 * Contributes every class-backed service referenced by the effective CQRS map.
 *
 * Using the effective map rather than the discovery index keeps production
 * factory compilation equivalent for both configured and discovered handlers
 * and listeners.
 */
final readonly class CqrsMapAutowireEntryContributor implements AutowireEntryContributorInterface
{
    public function __construct(private CqrsMapProviderInterface $mapProvider)
    {
    }

    public function entries(): iterable
    {
        $artifact = $this->mapProvider->map()->toArray();
        $services = [];

        foreach (['commands', 'queries'] as $section) {
            $handlers = $artifact[$section]['handlers'] ?? [];

            if (!is_array($handlers)) {
                continue;
            }

            foreach ($handlers as $descriptor) {
                if (is_array($descriptor)
                    && isset($descriptor['service'])
                    && is_string($descriptor['service'])
                ) {
                    $services[$descriptor['service']] = true;
                }
            }
        }

        $listeners = $artifact['commands']['listeners'] ?? [];

        if (is_array($listeners)) {
            foreach ($listeners as $descriptors) {
                if (!is_array($descriptors)) {
                    continue;
                }

                foreach ($descriptors as $descriptor) {
                    if (is_array($descriptor)
                        && isset($descriptor['service'])
                        && is_string($descriptor['service'])
                    ) {
                        $services[$descriptor['service']] = true;
                    }
                }
            }
        }

        ksort($services);

        foreach (array_keys($services) as $service) {
            if (class_exists($service)) {
                yield new AutowireEntry($service, 'CQRS effective map');
            }
        }
    }
}
