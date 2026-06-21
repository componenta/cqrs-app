<?php

namespace Componenta\CQRS\App\Query\Locator;

use Componenta\ClassFinder\Attribute\DevOnly;
use Componenta\ClassFinder\Attribute\ListenTo;
use Componenta\ClassFinder\Exception\ListenerAlreadyFinalizedException;
use Componenta\ClassFinder\FinalizableListenerInterface;
use Componenta\ClassFinder\FinalizationStateInterface;
use Componenta\CQRS\Query\Attribute\AsQueryHandler;
use Componenta\CQRS\Query\Exception\HandlerNotFoundException;
use Componenta\CQRS\Query\Locator\QueryHandlerLocatorInterface;
use Componenta\CQRS\Query\Locator\QuerySupportAwareInterface;
use Componenta\CQRS\Query\Resolver\QueryNameResolution;
use Componenta\CQRS\Query\Resolver\QueryNameResolverInterface;
use Componenta\Arrayable\Arrayable;
use Componenta\Tokenizer\ClassInfo;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

#[DevOnly]
#[ListenTo(AsQueryHandler::class, deepSearch: true)]
final class AttributeQueryHandlerLocator implements QueryHandlerLocatorInterface, QuerySupportAwareInterface, Arrayable, FinalizableListenerInterface, FinalizationStateInterface
{
    use QueryNameResolution;

    /** @var array<string, array{class-string, string}> */
    private array $handlerMap = [];

    private bool $initialized = false;

    public bool $finalized {
        get => $this->initialized;
    }

    public function __construct(
        private readonly ContainerInterface $container,
        ?QueryNameResolverInterface $resolver = null
    ) {
        $this->resolver = $resolver;
    }

    public function handle(ClassInfo $info): void
    {
        $hasClassAttribute = $info->reflector->getAttributes(AsQueryHandler::class) !== [];
        $methodsWithAttribute = $this->findMethodsWithAttribute($info);

        if ($hasClassAttribute && $methodsWithAttribute !== []) {
            throw new LogicException(sprintf(
                'Handler "%s" has #[AsQueryHandler] on both class and method "%s". Use one or the other.',
                $info->reflector->getName(),
                $methodsWithAttribute[0]->getName()
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

    public function locateFor(object $query): callable
    {
        if (!$this->initialized) {
            throw new LogicException('Locator is not initialized. Run discovery first.');
        }

        $queryName = $this->resolveQueryName($query);

        if (!isset($this->handlerMap[$queryName])) {
            throw new HandlerNotFoundException($query);
        }

        [$handlerClass, $method] = $this->handlerMap[$queryName];

        $handler = $this->container->get($handlerClass);

        return $method === '__invoke'
            ? $handler
            : $handler->$method(...);
    }

    public function supports(object $query): bool
    {
        return isset($this->handlerMap[$this->resolveQueryName($query)]);
    }

    public function toArray(): array
    {
        if (!$this->initialized) {
            throw new LogicException('Locator is not initialized. Run discovery first.');
        }

        return $this->handlerMap;
    }

    /** @return list<ReflectionMethod> */
    private function findMethodsWithAttribute(ClassInfo $info): array
    {
        $methods = [];

        foreach ($info->reflector->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(AsQueryHandler::class) !== []) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    private function registerClassHandler(ReflectionClass $reflector): void
    {
        $method = match (true) {
            $reflector->hasMethod('__invoke') => '__invoke',
            $reflector->hasMethod('handle') => 'handle',
            default => throw new LogicException(sprintf(
                'Handler "%s" has #[AsQueryHandler] on class but missing __invoke() or handle() method.',
                $reflector->getName()
            )),
        };

        /** @var AsQueryHandler $attribute */
        $attribute = $reflector->getAttributes(AsQueryHandler::class)[0]->newInstance();

        $queryName = $attribute->query ?? $this->inferQueryFromMethod($reflector->getMethod($method));

        $this->register($queryName, $reflector->getName(), $method);
    }

    private function registerMethodHandler(ReflectionClass $reflector, array $methods): void
    {
        foreach ($methods as $method) {
            /** @var AsQueryHandler $attribute */
            $attribute = $method->getAttributes(AsQueryHandler::class)[0]->newInstance();

            $queryName = $attribute->query ?? $this->inferQueryFromMethod($method);

            $this->register($queryName, $reflector->getName(), $method->getName());
        }
    }

    private function inferQueryFromMethod(ReflectionMethod $method): string
    {
        $params = $method->getParameters();
        $signature = $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()';

        if ($params === []) {
            throw new LogicException(sprintf(
                'Cannot infer query from "%s": no parameters. Specify query explicitly in attribute.',
                $signature
            ));
        }

        $type = $params[0]->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new LogicException(sprintf(
                'Cannot infer query from "%s": first parameter must be a class type.',
                $signature
            ));
        }

        return $type->getName();
    }

    private function register(string $queryName, string $handlerClass, string $method): void
    {
        if (isset($this->handlerMap[$queryName])) {
            if ($this->handlerMap[$queryName] === [$handlerClass, $method]) {
                return;
            }

            throw new LogicException(sprintf(
                'Multiple handlers for query "%s": %s and %s.',
                $queryName,
                $this->handlerMap[$queryName][0],
                $handlerClass
            ));
        }

        $this->handlerMap[$queryName] = [$handlerClass, $method];
    }
}
