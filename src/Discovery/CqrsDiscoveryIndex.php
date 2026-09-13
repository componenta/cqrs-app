<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Discovery;

use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\CQRS\App\Exception\InvalidDiscoveryDeclarationException;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\CQRS\Command\Attribute\AsCommandListener;
use Componenta\CQRS\Command\Event\CommandListenerInterface;
use Componenta\CQRS\Internal\RegistrationNormalizer;
use Componenta\CQRS\Query\Attribute\AsQueryHandler;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

/**
 * @phpstan-import-type Handler from RegistrationNormalizer
 * @phpstan-import-type Listener from RegistrationNormalizer
 */
final class CqrsDiscoveryIndex
{
    /** @var array<array-key, Handler> */
    private array $commandHandlers = [];
    /** @var array<array-key, Handler> */
    private array $queryHandlers = [];
    /** @var array<array-key, list<Listener>> */
    private array $commandListeners = [];
    private bool $loaded = false;
    private ?Throwable $failure = null;

    public function __construct(private readonly ClassIteratorInterface $classes)
    {
    }

    /** @return array<array-key, Handler> */
    public function commandHandlers(): array
    {
        $this->load();
        return $this->commandHandlers;
    }

    /** @return array<array-key, Handler> */
    public function queryHandlers(): array
    {
        $this->load();
        return $this->queryHandlers;
    }

    /** @return array<array-key, list<Listener>> */
    public function commandListeners(): array
    {
        $this->load();
        return $this->commandListeners;
    }

    private function load(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($this->loaded) {
            return;
        }
        try {
            foreach ($this->classes as $info) {
                $reflector = $info->reflector;
                $this->discoverHandlers($reflector, AsCommandHandler::class, 'command');
                $this->discoverHandlers($reflector, AsQueryHandler::class, 'query');
                $this->discoverListeners($reflector);
            }
            $this->commandHandlers = RegistrationNormalizer::handlers($this->commandHandlers, 'command_handlers');
            $this->queryHandlers = RegistrationNormalizer::handlers($this->queryHandlers, 'query_handlers');
            $this->commandListeners = RegistrationNormalizer::listeners($this->commandListeners);
            $this->loaded = true;
        } catch (Throwable $exception) {
            $this->commandHandlers = [];
            $this->queryHandlers = [];
            $this->commandListeners = [];
            $this->failure = $exception;
            throw $exception;
        }
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
                ['service' => $reflector->getName(), 'method' => $method->getName()],
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
                ['service' => $reflector->getName(), 'method' => $method->getName()],
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
            || (strcasecmp($name, '__invoke') !== 0 && str_starts_with($name, '__'))
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
            if ($additional->isPassedByReference() || $additional->isVariadic()) {
                throw new InvalidDiscoveryDeclarationException(sprintf(
                    'CQRS %s handler "%s::%s" cannot declare additional variadic or by-reference parameter "$%s".',
                    $kind,
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                    $additional->getName(),
                ));
            }

            if (!$additional->isOptional()) {
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
                fn (ReflectionType $member): bool => $this->typeCanAcceptObject($member),
            );
        }

        if ($type instanceof ReflectionIntersectionType) {
            return array_all(
                $type->getTypes(),
                fn (ReflectionType $member): bool => $this->typeCanAcceptObject($member),
            );
        }

        return $type instanceof ReflectionNamedType
            && (!$type->isBuiltin()
                || in_array($type->getName(), ['mixed', 'object', 'iterable', 'callable'], true));
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
                fn (ReflectionType $member): bool => $this->typeAccepts($member, $message, $scope),
            );
        }

        if ($type instanceof ReflectionIntersectionType) {
            return array_all(
                $type->getTypes(),
                fn (ReflectionType $member): bool => $this->typeAccepts($member, $message, $scope),
            );
        }

        if (!$type instanceof ReflectionNamedType) {
            return false;
        }

        if ($type->isBuiltin()) {
            return match ($type->getName()) {
                'mixed', 'object' => true,
                'iterable' => is_a($message, \Traversable::class, true),
                'callable' => new ReflectionClass($message)->hasMethod('__invoke'),
                default => false,
            };
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
     * @param Handler $descriptor
     */
    private function registerHandler(
        string $kind,
        string $message,
        array $descriptor,
        ReflectionMethod $method,
    ): void {
        $handlers = $kind === 'command'
            ? $this->commandHandlers
            : $this->queryHandlers;
        $existing = $handlers[$message] ?? null;

        if ($existing !== null && $existing !== $descriptor) {
            throw new InvalidDiscoveryDeclarationException(sprintf(
                'Multiple CQRS %s handlers are registered for "%s": "%s::%s" and "%s::%s".',
                $kind,
                $message,
                $existing['service'],
                $existing['method'],
                $descriptor['service'],
                $method->getName(),
            ));
        }

        if ($kind === 'command') {
            $this->commandHandlers[$message] = $descriptor;

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
            $descriptor = RegistrationNormalizer::listeners([
                $listener->command => [[
                    'service' => $reflector->getName(),
                    'events' => $listener->eventTypes,
                    'priority' => $listener->priority,
                ]],
            ])[$listener->command][0];
            $duplicate = false;

            foreach ($this->commandListeners[$listener->command] ?? [] as $existing) {
                if ($existing === $descriptor) {
                    $duplicate = true;
                    break;
                }
            }

            if (!$duplicate) {
                $this->commandListeners[$listener->command][] = $descriptor;
            }
        }
    }

}
