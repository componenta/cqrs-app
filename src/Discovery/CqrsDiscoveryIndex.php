<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Discovery;

use Componenta\ClassFinder\Exception\ListenerAlreadyFinalizedException;
use Componenta\ClassFinder\FinalizableListenerInterface;
use Componenta\ClassFinder\FinalizationStateInterface;
use Componenta\CQRS\App\Exception\InvalidDiscoveryDeclarationException;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\CQRS\Command\Attribute\AsCommandListener;
use Componenta\CQRS\Command\Event\CommandListenerInterface;
use Componenta\CQRS\Map\CommandListenerDescriptor;
use Componenta\CQRS\Map\CommandMetadataDescriptor;
use Componenta\CQRS\Map\CqrsMap;
use Componenta\CQRS\Map\HandlerDescriptor;
use Componenta\CQRS\Query\Attribute\AsQueryHandler;
use Componenta\Tokenizer\ClassInfo;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

final class CqrsDiscoveryIndex implements FinalizableListenerInterface, FinalizationStateInterface
{
    /** @var array<string, HandlerDescriptor> */
    private array $commandHandlers = [];

    /** @var array<string, HandlerDescriptor> */
    private array $queryHandlers = [];

    /** @var array<string, list<CommandListenerDescriptor>> */
    private array $commandListeners = [];

    /** @var array<string, array<class-string, CommandMetadataDescriptor>> */
    private array $commandMetadata = [];

    /** @var array<string, true> */
    private array $knownCommands = [];

    private ?CqrsMap $map = null;

    public bool $finalized {
        get => $this->map !== null;
    }

    /**
     * @param list<class-string> $metadataAttributes
     */
    public function __construct(private readonly array $metadataAttributes = [])
    {
    }

    public function handle(ClassInfo $info): void
    {
        if ($this->finalized) {
            throw ListenerAlreadyFinalizedException::forListener($this);
        }

        $reflector = $info->reflector;

        $this->discoverHandlers(
            $reflector,
            AsCommandHandler::class,
            'command',
        );
        $this->discoverHandlers(
            $reflector,
            AsQueryHandler::class,
            'query',
        );
        $this->discoverListeners($reflector);
        $this->discoverMetadata($reflector);
    }

    public function finalize(): void
    {
        if ($this->finalized) {
            throw ListenerAlreadyFinalizedException::forListener($this);
        }

        $map = new CqrsMap(
            commandHandlers: $this->commandHandlers,
            queryHandlers: $this->queryHandlers,
            commandListeners: $this->commandListeners,
            commandMetadata: $this->commandMetadata,
            knownCommands: $this->knownCommands,
        );

        $this->map = $map;
    }

    public function map(): CqrsMap
    {
        return $this->map ?? throw new InvalidDiscoveryDeclarationException(
            'CQRS discovery is not finalized. Complete application discovery before dispatch or compilation.',
        );
    }

    /**
     * @param ReflectionClass<object> $reflector
     * @param class-string<AsCommandHandler|AsQueryHandler> $attribute
     * @param 'command'|'query' $kind
     */
    private function discoverHandlers(
        ReflectionClass $reflector,
        string $attribute,
        string $kind,
    ): void {
        $classAttributes = $reflector->getAttributes($attribute);
        $methods = [];

        foreach ($reflector->getMethods() as $method) {
            if ($method->getAttributes($attribute) !== []) {
                $methods[] = $method;
            }
        }

        if ($classAttributes !== [] && $methods !== []) {
            throw new InvalidDiscoveryDeclarationException(sprintf(
                'CQRS %s handler "%s" declares its attribute on both the class and method "%s".',
                $kind,
                $reflector->getName(),
                $methods[0]->getName(),
            ));
        }

        if ($classAttributes === [] && $methods === []) {
            return;
        }

        if (!$reflector->isInstantiable()) {
            throw new InvalidDiscoveryDeclarationException(sprintf(
                'CQRS %s handler service "%s" must be instantiable.',
                $kind,
                $reflector->getName(),
            ));
        }

        if (count($classAttributes) > 1) {
            throw new InvalidDiscoveryDeclarationException(sprintf(
                'CQRS %s handler "%s" has duplicate class attributes.',
                $kind,
                $reflector->getName(),
            ));
        }

        if ($classAttributes !== []) {
            $method = match (true) {
                $reflector->hasMethod('__invoke') => $reflector->getMethod('__invoke'),
                $reflector->hasMethod('handle') => $reflector->getMethod('handle'),
                default => throw new InvalidDiscoveryDeclarationException(sprintf(
                    'CQRS %s handler "%s" must declare __invoke() or handle().',
                    $kind,
                    $reflector->getName(),
                )),
            };

            $this->registerHandler(
                $kind,
                $this->messageName($classAttributes[0], $method, $kind),
                new HandlerDescriptor($reflector->getName(), $method->getName()),
                $method,
            );

            return;
        }

        foreach ($methods as $method) {
            $attributes = $method->getAttributes($attribute);

            if (count($attributes) > 1) {
                throw new InvalidDiscoveryDeclarationException(sprintf(
                    'CQRS %s handler method "%s::%s" has duplicate attributes.',
                    $kind,
                    $reflector->getName(),
                    $method->getName(),
                ));
            }

            $this->registerHandler(
                $kind,
                $this->messageName($attributes[0], $method, $kind),
                new HandlerDescriptor($reflector->getName(), $method->getName()),
                $method,
            );
        }
    }

    /**
     * @param ReflectionAttribute<AsCommandHandler|AsQueryHandler> $attribute
     * @param 'command'|'query' $kind
     */
    private function messageName(
        ReflectionAttribute $attribute,
        ReflectionMethod $method,
        string $kind,
    ): string {
        $this->assertHandlerMethod($method, $kind);

        $arguments = $attribute->getArguments();
        $namedKey = $kind === 'command' ? 'command' : 'query';
        $explicit = $arguments[$namedKey] ?? $arguments[0] ?? null;

        if ($explicit !== null) {
            if (!is_string($explicit) || trim($explicit) === '') {
                throw new InvalidDiscoveryDeclarationException(sprintf(
                    'Explicit CQRS %s name on "%s::%s" must be a non-empty string.',
                    $kind,
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                ));
            }

            return $explicit;
        }

        $parameters = $method->getParameters();

        if ($parameters === []) {
            throw new InvalidDiscoveryDeclarationException(sprintf(
                'Cannot infer CQRS %s name from "%s::%s": the method has no parameters.',
                $kind,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ));

        }

        $type = $parameters[0]->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new InvalidDiscoveryDeclarationException(sprintf(
                'Cannot infer CQRS %s name from "%s::%s": the first parameter must be a class type; use an explicit message name for union or intersection types.',
                $kind,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ));
        }

        return $type->getName();
    }

    /**
     * @param 'command'|'query' $kind
     */
    private function assertHandlerMethod(ReflectionMethod $method, string $kind): void
    {
        if (!$method->isPublic() || $method->isStatic()) {
            throw new InvalidDiscoveryDeclarationException(sprintf(
                'CQRS %s handler method "%s::%s" must be public and non-static.',
                $kind,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ));
        }
    }

    /**
     * @param 'command'|'query' $kind
     */
    private function registerHandler(
        string $kind,
        string $message,
        HandlerDescriptor $descriptor,
        ReflectionMethod $method,
    ): void {
        $handlers = $kind === 'command'
            ? $this->commandHandlers
            : $this->queryHandlers;
        $existing = $handlers[$message] ?? null;

        if ($existing !== null && !$existing->equals($descriptor)) {
            throw new InvalidDiscoveryDeclarationException(sprintf(
                'Multiple CQRS %s handlers are registered for "%s": "%s::%s" and "%s::%s".',
                $kind,
                $message,
                $existing->service,
                $existing->method,
                $descriptor->service,
                $method->getName(),
            ));
        }

        if ($kind === 'command') {
            $this->commandHandlers[$message] = $descriptor;
            $this->knownCommands[$message] = true;

            return;
        }

        $this->queryHandlers[$message] = $descriptor;
    }

    /**
     * @param ReflectionClass<object> $reflector
     */
    private function discoverListeners(ReflectionClass $reflector): void
    {
        $attributes = $reflector->getAttributes(AsCommandListener::class);

        if ($attributes === []) {
            return;
        }

        if (!$reflector->isInstantiable()
            || !$reflector->implementsInterface(CommandListenerInterface::class)
        ) {
            throw new InvalidDiscoveryDeclarationException(sprintf(
                'CQRS listener "%s" must be instantiable and implement %s.',
                $reflector->getName(),
                CommandListenerInterface::class,
            ));
        }

        foreach ($attributes as $attribute) {
            /** @var AsCommandListener $listener */
            $listener = $attribute->newInstance();
            $descriptor = new CommandListenerDescriptor(
                $reflector->getName(),
                $listener->eventTypes,
                $listener->priority,
            );
            $duplicate = false;

            foreach ($this->commandListeners[$listener->command] ?? [] as $existing) {
                if ($existing->equals($descriptor)) {
                    $duplicate = true;
                    break;
                }
            }

            if (!$duplicate) {
                $this->commandListeners[$listener->command][] = $descriptor;
            }

            $this->knownCommands[$listener->command] = true;
        }
    }

    /**
     * @param ReflectionClass<object> $reflector
     */
    private function discoverMetadata(ReflectionClass $reflector): void
    {
        foreach ($this->metadataAttributes as $attributeClass) {
            $attributes = $reflector->getAttributes($attributeClass);

            if ($attributes === []) {
                continue;
            }

            if (count($attributes) > 1) {
                throw new InvalidDiscoveryDeclarationException(sprintf(
                    'Command "%s" has repeated metadata attribute "%s"; only one descriptor per attribute is supported.',
                    $reflector->getName(),
                    $attributeClass,
                ));
            }

            $command = $reflector->getName();
            $this->commandMetadata[$command][$attributeClass]
                = new CommandMetadataDescriptor(
                    $attributeClass,
                    $attributes[0]->getArguments(),
                );
            $this->knownCommands[$command] = true;
        }
    }
}
