<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Command\Locator;

use Componenta\ClassFinder\Attribute\DevOnly;
use Componenta\ClassFinder\Attribute\ListenTo;
use Componenta\ClassFinder\Exception\ListenerAlreadyFinalizedException;
use Componenta\ClassFinder\FinalizableListenerInterface;
use Componenta\ClassFinder\FinalizationStateInterface;
use Componenta\CQRS\Command\Attribute\AsCommandListener;
use Componenta\CQRS\Command\Event\CommandFailedEvent;
use Componenta\CQRS\Command\Event\CommandListenerInterface;
use Componenta\CQRS\Command\Event\CommandProcessedEvent;
use Componenta\CQRS\Command\Event\CommandProcessEvent;
use Componenta\CQRS\Command\Locator\CommandListenersLocatorInterface;
use Componenta\CQRS\Command\Locator\CommandNameResolution;
use Componenta\CQRS\Command\Resolver\CommandNameResolverInterface;
use Componenta\Arrayable\Arrayable;
use Componenta\Tokenizer\ClassInfo;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionClass;

#[DevOnly]
#[ListenTo(AsCommandListener::class)]
final class AttributeCommandListenersLocator implements CommandListenersLocatorInterface, FinalizableListenerInterface, FinalizationStateInterface, Arrayable
{
    use CommandNameResolution;

    /**
     * @var array<string, list<array{
     *     class: class-string<CommandListenerInterface>,
     *     eventTypes: list<class-string>,
     *     priority: int,
     * }>>
     */
    private array $listenerMap = [];

    private bool $initialized = false;

    public bool $finalized {
        get => $this->initialized;
    }

    public function __construct(
        private readonly ContainerInterface $container,
        ?CommandNameResolverInterface $resolver = null,
    ) {
        $this->resolver = $resolver;
    }

    public function handle(ClassInfo $info): void
    {
        if ($info->reflector->getAttributes(AsCommandListener::class) === []) {
            return;
        }

        $this->registerListener($info->reflector);
    }

    public function finalize(): void
    {
        if ($this->initialized) {
            throw ListenerAlreadyFinalizedException::forListener($this);
        }

        $this->initialized = true;
    }

    public function locateFor(CommandProcessEvent|CommandProcessedEvent|CommandFailedEvent $event): iterable
    {
        if (!$this->initialized) {
            throw new LogicException('Locator is not initialized. Run discovery first.');
        }

        $commandName = $this->resolveCommandName($event->operation->command);

        if (!isset($this->listenerMap[$commandName])) {
            return;
        }

        $eventClass = $event::class;

        foreach ($this->listenerMap[$commandName] as $entry) {
            if ($entry['eventTypes'] !== [] && !\in_array($eventClass, $entry['eventTypes'], true)) {
                continue;
            }

            yield $this->container->get($entry['class']);
        }
    }

    public function toArray(): array
    {
        if (!$this->initialized) {
            throw new LogicException('Locator is not initialized. Run discovery first.');
        }

        return $this->listenerMap;
    }

    private function registerListener(ReflectionClass $reflector): void
    {
        $name = $reflector->getName();

        if (!$reflector->implementsInterface(CommandListenerInterface::class)) {
            throw new LogicException(sprintf(
                'Listener "%s" has #[AsCommandListener] but does not implement %s.',
                $name,
                CommandListenerInterface::class,
            ));
        }

        $commandNames = [];

        foreach ($reflector->getAttributes(AsCommandListener::class) as $attribute) {
            /** @var AsCommandListener $instance */
            $instance = $attribute->newInstance();

            $entry = [
                'class'      => $name,
                'eventTypes' => $instance->eventTypes,
                'priority'   => $instance->priority,
            ];

            if (!in_array($entry, $this->listenerMap[$instance->command] ?? [], true)) {
                $this->listenerMap[$instance->command][] = $entry;
            }

            $commandNames[] = $instance->command;
        }

        foreach (array_unique($commandNames) as $commandName) {
            $this->sortByPriority($commandName);
        }
    }

    private function sortByPriority(string $commandName): void
    {
        usort(
            $this->listenerMap[$commandName],
            static fn (array $a, array $b) => $b['priority'] <=> $a['priority'],
        );
    }
}
