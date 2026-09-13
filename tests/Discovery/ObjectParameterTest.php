<?php

declare(strict_types=1);

use Componenta\ClassFinder\ClassIterator;
use Componenta\CQRS\App\Build\CqrsBuilder;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\App\Exception\InvalidDiscoveryDeclarationException;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\CQRS\Command\Locator\CommandHandlerLocator;
use Componenta\CQRS\Query\Attribute\AsQueryHandler;
use Componenta\CQRS\Query\Locator\QueryHandlerLocator;
use Componenta\Tokenizer\ClassInfo;

final class IterableObjectMessage extends ArrayObject
{
}
final class CallableObjectMessage
{
    public function __invoke(): string
    {
        return 'callable result';
    }
}
#[AsCommandHandler(IterableObjectMessage::class)]
#[AsQueryHandler(IterableObjectMessage::class)]
final class IterableObjectHandler
{
    public function __invoke(iterable $message): string
    {
        foreach ($message as $value) {
            return $value;
        }
        return 'empty';
    }
}
#[AsCommandHandler(CallableObjectMessage::class)]
#[AsQueryHandler(CallableObjectMessage::class)]
final class CallableObjectHandler
{
    public function __invoke(callable $message): string
    {
        return $message();
    }
}
#[AsCommandHandler(stdClass::class)]
final class NonIterableObjectHandler
{
    public function __invoke(iterable $message): void
    {
    }
}
#[AsQueryHandler(stdClass::class)]
final class NonCallableObjectHandler
{
    public function __invoke(callable $message): void
    {
    }
}

it('dispatches iterable and callable object messages from source and built maps', function (
    string $handlerClass,
    object $message,
    string $expected,
    bool $built,
): void {
    $index = new CqrsDiscoveryIndex(new ClassIterator([$handlerClass => new ClassInfo($handlerClass)]));
    $composition = (new \Componenta\Config\ConfigFactory())->create(
        new \Componenta\Config\Environment([]),
    );
    $container = (new \Componenta\DI\ContainerFactory())->create($composition->config, $composition->dependencies);
    $file = sys_get_temp_dir() . '/cqrs_object_parameter_' . bin2hex(random_bytes(8)) . '.php';
    try {
        if ($built) {
            (new CqrsBuilder($index, $file))->build();
            $maps = require $file;
        } else {
            $maps = ['command_handlers' => $index->commandHandlers(), 'query_handlers' => $index->queryHandlers()];
        }
        $commands = new CommandHandlerLocator($maps['command_handlers'], $container);
        $queries = new QueryHandlerLocator($maps['query_handlers'], $container);
        expect(($commands->locateFor($message))($message))->toBe($expected)
            ->and(($queries->locateFor($message))($message))->toBe($expected);
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
    }
})->with([
    'iterable' => [IterableObjectHandler::class, new IterableObjectMessage(['iterable result']), 'iterable result'],
    'callable' => [CallableObjectHandler::class, new CallableObjectMessage(), 'callable result'],
])->with([false, true]);

it('rejects an explicit object class incompatible with iterable or callable', function (string $handlerClass): void {
    $index = new CqrsDiscoveryIndex(new ClassIterator([$handlerClass => new ClassInfo($handlerClass)]));
    expect(fn () => $index->commandHandlers())->toThrow(InvalidDiscoveryDeclarationException::class, 'is incompatible');
})->with([NonIterableObjectHandler::class, NonCallableObjectHandler::class]);
