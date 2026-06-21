<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Command\Locator;

use Componenta\CQRS\Command\Event\CommandFailedEvent;
use Componenta\CQRS\Command\Event\CommandListenerInterface;
use Componenta\CQRS\Command\Event\CommandProcessedEvent;
use Componenta\CQRS\Command\Event\CommandProcessEvent;
use Componenta\CQRS\Command\Locator\CommandListenersLocatorInterface;

final readonly class CommandListenersLocatorCollector implements CommandListenersLocatorInterface
{
    public function __construct(
        private CommandListenersLocatorInterface $fallback,
        private AttributeCommandListenersLocator $attributeLocator,
    ) {}

    public function locateFor(CommandProcessEvent|CommandProcessedEvent|CommandFailedEvent $event): iterable
    {
        $listeners = [];

        foreach ($this->fallback->locateFor($event) as $listener) {
            if ($listener instanceof CommandListenerInterface) {
                $listeners[$listener::class] = $listener;
            }
        }

        if ($this->attributeLocator->finalized) {
            foreach ($this->attributeLocator->locateFor($event) as $listener) {
                if ($listener instanceof CommandListenerInterface) {
                    $listeners[$listener::class] = $listener;
                }
            }
        }

        return array_values($listeners);
    }
}
