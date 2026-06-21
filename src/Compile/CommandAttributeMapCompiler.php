<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Compile;

use Componenta\CQRS\Command\Attribute\Async;
use Componenta\CQRS\Command\Attribute\Lock;
use Componenta\CQRS\Command\Attribute\Retry;
use ReflectionClass;

final class CommandAttributeMapCompiler
{
    /**
     * @param iterable<class-string> $classes
     * @param iterable<class-string> $knownCommandClasses
     * @return array{
     *     known: array<class-string, true>,
     *     attributes: array<class-string, array<string, array<string, mixed>>>
     * }
     */
    public function compile(iterable $classes, iterable $knownCommandClasses = []): array
    {
        $known = [];
        foreach ($knownCommandClasses as $class) {
            if (is_string($class) && $class !== '') {
                $known[$class] = true;
            }
        }

        $attributes = [];

        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($class);
            } catch (\ReflectionException) {
                continue;
            }

            $metadata = $this->compileClass($reflection);

            if ($metadata !== []) {
                $attributes[$reflection->getName()] = $metadata;
                $known[$reflection->getName()] = true;
            }
        }

        ksort($known);
        ksort($attributes);

        return [
            'known' => $known,
            'attributes' => $attributes,
        ];
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<string, array<string, mixed>>
     */
    private function compileClass(ReflectionClass $reflection): array
    {
        $metadata = [];

        $async = $this->first($reflection, Async::class);
        if ($async instanceof Async) {
            $metadata['async'] = [
                'transport' => $async->transport,
                'delay' => $async->delay,
            ];
        }

        $retry = $this->first($reflection, Retry::class);
        if ($retry instanceof Retry) {
            $metadata['retry'] = [
                'attempts' => $retry->attempts,
                'delayMs' => $retry->delayMs,
                'multiplier' => $retry->multiplier,
                'maxDelayMs' => $retry->maxDelayMs,
            ];
        }

        $lock = $this->first($reflection, Lock::class);
        if ($lock instanceof Lock) {
            $metadata['lock'] = [
                'key' => $lock->key,
                'ttl' => $lock->ttl,
                'blocking' => $lock->blocking,
            ];
        }

        return $metadata;
    }

    /**
     * @template T of object
     * @param ReflectionClass<object> $reflection
     * @param class-string<T> $attribute
     * @return T|null
     */
    private function first(ReflectionClass $reflection, string $attribute): ?object
    {
        $attributes = $reflection->getAttributes($attribute);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }
}
