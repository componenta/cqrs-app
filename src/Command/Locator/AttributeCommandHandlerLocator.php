<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Command\Locator;

use Componenta\ClassFinder\Attribute\DevOnly;
use Componenta\ClassFinder\Attribute\ListenTo;
use Componenta\ClassFinder\Exception\ListenerAlreadyFinalizedException;
use Componenta\ClassFinder\FinalizableListenerInterface;
use Componenta\ClassFinder\FinalizationStateInterface;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\CQRS\Command\Exception\HandlerNotFoundException;
use Componenta\CQRS\Command\Locator\CommandHandlerLocatorInterface;
use Componenta\CQRS\Command\Locator\CommandNameResolution;
use Componenta\CQRS\Command\Locator\CommandSupportAwareInterface;
use Componenta\CQRS\Command\Resolver\CommandNameResolverInterface;
use Componenta\Arrayable\Arrayable;
use Componenta\Tokenizer\ClassInfo;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

#[DevOnly]
#[ListenTo(AsCommandHandler::class, deepSearch: true)]
final class AttributeCommandHandlerLocator implements CommandHandlerLocatorInterface, CommandSupportAwareInterface, FinalizableListenerInterface, FinalizationStateInterface, Arrayable
{
    use CommandNameResolution;

    /** @var array<string, array{class-string, string}> */
    private array $handlerMap = [];

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
        $hasClassAttribute = $info->reflector->getAttributes(AsCommandHandler::class) !== [];
        $methodsWithAttribute = $this->findMethodsWithAttribute($info);

        if ($hasClassAttribute && $methodsWithAttribute !== []) {
            throw new LogicException(sprintf(
                'Handler "%s" has #[AsCommandHandler] on both class and method "%s". Use one or the other.',
                $info->reflector->getName(),
                $methodsWithAttribute[0]->getName(),
            ));
        }

        if ($hasClassAttribute) {
            $this->registerClassHandler($info->reflector);
            return;
        }

        if ($methodsWithAttribute !== []) {
            $this->registerMethodHandler($info->reflector, $methodsWithAttribute);
        }
    }

    public function finalize(): void
    {
        if ($this->initialized) {
            throw ListenerAlreadyFinalizedException::forListener($this);
        }

        $this->initialized = true;
    }

    public function locateFor(object $command): callable
    {
        if (!$this->initialized) {
            throw new LogicException('Locator is not initialized. Run discovery first.');
        }

        $commandName = $this->resolveCommandName($command);

        if (!isset($this->handlerMap[$commandName])) {
            throw new HandlerNotFoundException($commandName);
        }

        [$handlerClass, $method] = $this->handlerMap[$commandName];

        $handler = $this->container->get($handlerClass);

        return $method === '__invoke'
            ? $handler
            : $handler->$method(...);
    }

    public function supports(object $command): bool
    {
        return isset($this->handlerMap[$this->resolveCommandName($command)]);
    }

    public function toArray(): array
    {
        if (!$this->initialized) {
            throw new LogicException('Locator is not initialized. Run discovery first.');
        }

        return $this->handlerMap;
    }

    /**
     * @return list<ReflectionMethod>
     */
    private function findMethodsWithAttribute(ClassInfo $info): array
    {
        $methods = [];

        foreach ($info->reflector->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(AsCommandHandler::class) !== []) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * @param ReflectionClass<object> $reflector
     */
    private function registerClassHandler(ReflectionClass $reflector): void
    {
        $method = match (true) {
            $reflector->hasMethod('__invoke') => '__invoke',
            $reflector->hasMethod('handle') => 'handle',
            default => throw new LogicException(sprintf(
                'Handler "%s" has #[AsCommandHandler] on class but missing __invoke() or handle() method.',
                $reflector->getName(),
            )),
        };

        /** @var AsCommandHandler $attribute */
        $attribute = $reflector->getAttributes(AsCommandHandler::class)[0]->newInstance();

        $commandName = $attribute->command ?? $this->inferCommandFromMethod($reflector->getMethod($method));

        $this->register($commandName, $reflector->getName(), $method);
    }

    /**
     * @param ReflectionClass<object> $reflector
     * @param list<ReflectionMethod> $methods
     */
    private function registerMethodHandler(ReflectionClass $reflector, array $methods): void
    {
        if (count($methods) > 1) {
            throw new LogicException(sprintf(
                'Handler "%s" has #[AsCommandHandler] on multiple methods: %s. Only one method allowed.',
                $reflector->getName(),
                implode(', ', array_map(fn(ReflectionMethod $m) => $m->getName(), $methods)),
            ));
        }

        $method = $methods[0];

        /** @var AsCommandHandler $attribute */
        $attribute = $method->getAttributes(AsCommandHandler::class)[0]->newInstance();

        $commandName = $attribute->command ?? $this->inferCommandFromMethod($method);

        $this->register($commandName, $reflector->getName(), $method->getName());
    }

    private function inferCommandFromMethod(ReflectionMethod $method): string
    {
        $params = $method->getParameters();
        $signature = $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()';

        if ($params === []) {
            throw new LogicException(sprintf(
                'Cannot infer command from "%s": no parameters. Specify command explicitly in attribute.',
                $signature,
            ));
        }

        $type = $params[0]->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new LogicException(sprintf(
                'Cannot infer command from "%s": first parameter must be a class type. Specify command explicitly in attribute.',
                $signature,
            ));
        }

        return $type->getName();
    }

    /**
     * @param class-string $handlerClass
     */
    private function register(string $commandName, string $handlerClass, string $method): void
    {
        if (isset($this->handlerMap[$commandName])) {
            if ($this->handlerMap[$commandName] === [$handlerClass, $method]) {
                return;
            }

            throw new LogicException(sprintf(
                'Multiple handlers for command "%s": %s and %s.',
                $commandName,
                $this->handlerMap[$commandName][0],
                $handlerClass,
            ));
        }

        $this->handlerMap[$commandName] = [$handlerClass, $method];
    }
}
