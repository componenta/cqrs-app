<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Discovery;

use Attribute;
use Componenta\ClassFinder\Attribute\DevOnly;
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
use Componenta\DI\Compile\Autowire\AutowireEntry;
use Componenta\DI\Compile\Autowire\AutowireEntryContributorInterface;
use Componenta\Tokenizer\ClassInfo;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

#[DevOnly]
final class CqrsDiscoveryIndex implements FinalizableListenerInterface, FinalizationStateInterface, AutowireEntryContributorInterface
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

    public function entries(): iterable
    {
        $services = [];

        foreach ([...array_values($this->commandHandlers), ...array_values($this->queryHandlers)] as $handler) {
            $services[$handler->service] = true;
        }

        foreach ($this->commandListeners as $listeners) {
            foreach ($listeners as $listener) {
                $services[$listener->service] = true;
            }
        }

        ksort($services);
        foreach (array_keys($services) as $service) {
            if (class_exists($service)) {
                yield new AutowireEntry($service, 'CQRS discovery');
            }
        }
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
            if ($method->getDeclaringClass()->getName() === $reflector->getName()
                && $method->getAttributes($attribute) !== []) {
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

        if ($classAttributes === [] && !$reflector->isInstantiable()) {
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
        $messageParameter = $this->messageParameter($method, $kind);

        $arguments = $attribute->getArguments();
        $namedKey = $kind === 'command' ? 'command' : 'query';
        foreach (array_keys($arguments) as $argument) {
            if ($argument !== 0 && $argument !== $namedKey) {
                throw new InvalidDiscoveryDeclarationException(sprintf(
                    'CQRS %s handler attribute on "%s::%s" has unsupported argument "%s".',
                    $kind,
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                    (string) $argument,
                ));
            }
        }

        if (count($arguments) > 1) {
            throw new InvalidDiscoveryDeclarationException(sprintf(
                'CQRS %s handler attribute on "%s::%s" must declare at most one message name.',
                $kind,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ));
        }

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

            if ((class_exists($explicit) || interface_exists($explicit))
                && !$this->parameterAccepts($messageParameter, $explicit)
            ) {
                throw new InvalidDiscoveryDeclarationException(sprintf(
                    'Explicit CQRS %s "%s" is incompatible with the first parameter of "%s::%s".',
                    $kind,
                    $explicit,
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                ));
            }

            return $explicit;
        }

        $type = $messageParameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new InvalidDiscoveryDeclarationException(sprintf(
                'Cannot infer CQRS %s name from "%s::%s": the first parameter must be a class type; use an explicit message name for union or intersection types.',
                $kind,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ));
        }

        $declaringClass = $method->getDeclaringClass();

        return match ($type->getName()) {
            'self' => $declaringClass->getName(),
            'parent' => $this->parentClassName($declaringClass)
                ?? throw new InvalidDiscoveryDeclarationException('Cannot infer a parent CQRS message type without a parent class.'),
            default => $type->getName(),
        };
    }

    /**
     * @param 'command'|'query' $kind
     */
    private function assertHandlerMethod(ReflectionMethod $method, string $kind): void
    {
        $name = $method->getName();

        if (!$method->isPublic()
            || $method->isStatic()
            || ($name !== '__invoke' && str_starts_with($name, '__'))
        ) {
            throw new InvalidDiscoveryDeclarationException(sprintf(
                'CQRS %s handler method "%s::%s" must be a public non-static operation method.',
                $kind,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ));
        }
    }

    /**
     * @param 'command'|'query' $kind
     */
    private function messageParameter(ReflectionMethod $method, string $kind): ReflectionParameter
    {
        $parameter = $method->getParameters()[0] ?? null;

        if ($parameter === null) {
            throw new InvalidDiscoveryDeclarationException(sprintf(
                'CQRS %s handler "%s::%s" must accept the message in its first parameter slot.',
                $kind,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ));
        }

        if ($parameter->isPassedByReference() || $parameter->isVariadic()) {
            throw new InvalidDiscoveryDeclarationException(sprintf(
                'The first parameter of CQRS %s handler "%s::%s" must be a normal by-value parameter.',
                $kind,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ));
        }

        foreach (array_slice($method->getParameters(), 1) as $additional) {
            if (!$additional->isOptional() && !$additional->isVariadic()) {
                throw new InvalidDiscoveryDeclarationException(sprintf(
                    'CQRS %s handler "%s::%s" cannot require additional parameter "$%s"; the runtime supplies only the message.',
                    $kind,
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                    $additional->getName(),
                ));
            }
        }

        if (!$this->typeCanAcceptObject($parameter->getType())) {
            throw new InvalidDiscoveryDeclarationException(sprintf(
                'The first parameter of CQRS %s handler "%s::%s" must accept an object message.',
                $kind,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ));
        }

        return $parameter;
    }

    private function typeCanAcceptObject(?ReflectionType $type): bool
    {
        if ($type === null) {
            return true;
        }

        if ($type instanceof ReflectionUnionType) {
            return array_any(
                $type->getTypes(),
                fn(ReflectionType $member): bool => $this->typeCanAcceptObject($member),
            );
        }

        if ($type instanceof ReflectionIntersectionType) {
            return array_all(
                $type->getTypes(),
                fn(ReflectionType $member): bool => $this->typeCanAcceptObject($member),
            );
        }

        return $type instanceof ReflectionNamedType
            && (!$type->isBuiltin()
                || in_array($type->getName(), ['mixed', 'object'], true));
    }

    /** @param class-string $message */
    private function parameterAccepts(ReflectionParameter $parameter, string $message): bool
    {
        $type = $parameter->getType();

        return $type === null || $this->typeAccepts($type, $message, $parameter->getDeclaringClass());
    }

    /**
     * @param class-string $message
     * @param ReflectionClass<object>|null $scope
     */
    private function typeAccepts(ReflectionType $type, string $message, ?ReflectionClass $scope): bool
    {
        if ($type instanceof ReflectionUnionType) {
            return array_any(
                $type->getTypes(),
                fn(ReflectionType $member): bool => $this->typeAccepts($member, $message, $scope),
            );
        }

        if ($type instanceof ReflectionIntersectionType) {
            return array_all(
                $type->getTypes(),
                fn(ReflectionType $member): bool => $this->typeAccepts($member, $message, $scope),
            );
        }

        if (!$type instanceof ReflectionNamedType) {
            return false;
        }

        if ($type->isBuiltin()) {
            return in_array($type->getName(), ['mixed', 'object'], true);
        }

        $accepted = match ($type->getName()) {
            'self' => $scope?->getName(),
            'parent' => $scope === null ? null : $this->parentClassName($scope),
            default => $type->getName(),
        };

        return $accepted !== null && is_a($message, $accepted, true);
    }

    /** @param ReflectionClass<object> $class */
    private function parentClassName(ReflectionClass $class): ?string
    {
        $parent = $class->getParentClass();

        return $parent === false ? null : $parent->getName();
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
            if (!class_exists($attributeClass)) {
                throw new InvalidDiscoveryDeclarationException(sprintf(
                    'Command metadata attribute class "%s" does not exist.',
                    $attributeClass,
                ));
            }

            $attributeDeclaration = new ReflectionClass($attributeClass);
            $attributeMetadata = $attributeDeclaration->getAttributes(Attribute::class);

            if ($attributeMetadata === []) {
                throw new InvalidDiscoveryDeclarationException(sprintf(
                    'Command metadata class "%s" is not declared with #[Attribute].',
                    $attributeClass,
                ));
            }

            $flags = $attributeMetadata[0]->newInstance()->flags;
            if (($flags & Attribute::TARGET_CLASS) === 0) {
                throw new InvalidDiscoveryDeclarationException(sprintf(
                    'Command metadata attribute "%s" must allow class targets.',
                    $attributeClass,
                ));
            }

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
