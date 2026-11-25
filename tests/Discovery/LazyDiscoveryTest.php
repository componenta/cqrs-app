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


final class LazyDiscoverySecondCommand
{
}

#[AsCommandHandler]
final class LazyDiscoverySecondHandler
{
    public function __invoke(LazyDiscoverySecondCommand $command): void
    {
    }
}

it('rejects reentrant discovery without corrupting the active scan', function (): void {
    $index = null;
    $reentryFailure = null;
    $classes = new ClassIterator((static function () use (&$index, &$reentryFailure): Generator {
        yield LazyDiscoveryHandler::class => new ClassInfo(LazyDiscoveryHandler::class);

        try {
            $index->queryHandlers();
        } catch (LogicException $caught) {
            $reentryFailure = $caught;
        }

        yield LazyDiscoverySecondHandler::class => new ClassInfo(LazyDiscoverySecondHandler::class);
    })());
    $index = new CqrsDiscoveryIndex($classes);

    $expected = [
        LazyDiscoveryCommand::class => ['service' => LazyDiscoveryHandler::class, 'method' => '__invoke'],
        LazyDiscoverySecondCommand::class => ['service' => LazyDiscoverySecondHandler::class, 'method' => '__invoke'],
    ];
    expect($index->commandHandlers())->toBe($expected)
        ->and($reentryFailure)->toBeInstanceOf(LogicException::class)
        ->and($index->commandHandlers())->toBe($expected)
        ->and($index->queryHandlers())->toBe([])
        ->and($index->commandListeners())->toBe([]);
});

it('rejects a competing discovery during a suspended fiber without poisoning the original scan', function (): void {
    $index = new CqrsDiscoveryIndex(new ClassIterator((static function (): Generator {
        yield LazyDiscoveryHandler::class => new ClassInfo(LazyDiscoveryHandler::class);
        Fiber::suspend('discovering');
        yield LazyDiscoverySecondHandler::class => new ClassInfo(LazyDiscoverySecondHandler::class);
    })()));
    $fiber = new Fiber(static fn () => $index->commandHandlers());
    expect($fiber->start())->toBe('discovering')
        ->and(fn () => $index->commandListeners())->toThrow(LogicException::class);
    $fiber->resume();

    $expected = [
        LazyDiscoveryCommand::class => ['service' => LazyDiscoveryHandler::class, 'method' => '__invoke'],
        LazyDiscoverySecondCommand::class => ['service' => LazyDiscoverySecondHandler::class, 'method' => '__invoke'],
    ];
    expect($fiber->getReturn())->toBe($expected)
        ->and($index->commandHandlers())->toBe($expected)
        ->and($index->queryHandlers())->toBe([]);
});

it('preserves the original failure when a reentrant lookup escapes the source', function (): void {
    $index = null;
    $classes = new ClassIterator((static function () use (&$index): Generator {
        yield LazyDiscoveryHandler::class => new ClassInfo(LazyDiscoveryHandler::class);
        $index->queryHandlers();
    })());
    $index = new CqrsDiscoveryIndex($classes);
    $failure = null;
    try {
        $index->commandHandlers();
        test()->fail('Expected a reentrant discovery failure.');
    } catch (LogicException $caught) {
        $failure = $caught;
    }
    try {
        $index->commandListeners();
        test()->fail('Expected the original discovery failure.');
    } catch (LogicException $caught) {
        expect($caught)->toBe($failure);
    }
});
