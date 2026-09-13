<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Tests\Discovery\InvocationCase;

use Componenta\ClassFinder\ClassIterator;
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\CQRS\Command\Locator\CommandHandlerLocator;
use Componenta\CQRS\Query\Attribute\AsQueryHandler;
use Componenta\CQRS\Query\Locator\QueryHandlerLocator;
use Componenta\DI\ContainerFactory;
use Componenta\Tokenizer\ClassInfo;

final class Message
{
}

#[AsCommandHandler]
final class ClassCommandHandler
{
    public function __INVOKE(Message $message): string
    {
        return 'handled';
    }
}

final class MethodCommandHandler
{
    #[AsCommandHandler]
    public function __Invoke(Message $message): string
    {
        return 'handled';
    }
}

#[AsQueryHandler]
final class ClassQueryHandler
{
    public function __INVOKE(Message $message): string
    {
        return 'handled';
    }
}

final class MethodQueryHandler
{
    #[AsQueryHandler]
    public function __Invoke(Message $message): string
    {
        return 'handled';
    }
}

it('discovers and invokes handlers regardless of invoke method casing', function (
    string $class,
    string $method,
    bool $query,
): void {
    $index = new CqrsDiscoveryIndex(new ClassIterator([$class => new ClassInfo($class)]));
    $container = new ContainerFactory()->create(new Config([], new Environment([])), new DependencyDefinitions([]));

    $handlers = $query ? $index->queryHandlers() : $index->commandHandlers();
    $locator = $query
        ? new QueryHandlerLocator($handlers, $container)
        : new CommandHandlerLocator($handlers, $container);
    $message = new Message();

    expect($handlers)->toBe([Message::class => ['service' => $class, 'method' => $method]])
        ->and($locator->locateFor($message)($message))->toBe('handled');
})->with([
    'command class' => [ClassCommandHandler::class, '__INVOKE', false],
    'command method' => [MethodCommandHandler::class, '__Invoke', false],
    'query class' => [ClassQueryHandler::class, '__INVOKE', true],
    'query method' => [MethodQueryHandler::class, '__Invoke', true],
]);
