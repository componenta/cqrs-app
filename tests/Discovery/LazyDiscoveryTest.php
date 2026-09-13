<?php

declare(strict_types=1);

use Componenta\ClassFinder\ClassIterator;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\Tokenizer\ClassInfo;

final class LazyDiscoveryCommand
{
}
#[AsCommandHandler]
final class LazyDiscoveryHandler
{
    public function __invoke(LazyDiscoveryCommand $command): void
    {
    }
}

it('discovers lazily once and shares all sections', function (): void {
    $visited = 0;
    $classes = new ClassIterator((static function () use (&$visited): Generator {
        ++$visited;
        yield LazyDiscoveryHandler::class => new ClassInfo(LazyDiscoveryHandler::class);
    })());
    $index = new CqrsDiscoveryIndex($classes);
    expect($visited)->toBe(0);
    expect($index->commandHandlers())->toBe([
        LazyDiscoveryCommand::class => ['service' => LazyDiscoveryHandler::class, 'method' => '__invoke'],
    ])->and($index->queryHandlers())->toBe([])
      ->and($index->commandListeners())->toBe([])
      ->and($visited)->toBe(1);
});

it('never publishes partial discovery after an interrupted source', function (): void {
    $failure = new RuntimeException('source failed');
    $index = new CqrsDiscoveryIndex(new ClassIterator((static function () use ($failure): Generator {
        yield LazyDiscoveryHandler::class => new ClassInfo(LazyDiscoveryHandler::class);
        throw $failure;
    })()));
    foreach (['commandHandlers', 'queryHandlers', 'commandListeners'] as $method) {
        try {
            $index->$method();
            test()->fail('Partial discovery must not become available.');
        } catch (RuntimeException $exception) {
            expect($exception)->toBe($failure);
        }
    }
});
