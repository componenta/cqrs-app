<?php

declare(strict_types=1);

use Componenta\ClassFinder\ClassIterator;
use Componenta\CQRS\App\Build\CqrsBuilder;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\App\Tests\BuildFixture;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\Tokenizer\ClassInfo;

final class BuildCommandMessage {}
#[AsCommandHandler]
final class BuildCommandHandler
{
    public function __invoke(BuildCommandMessage $command): void {}
}

it('builds a complete map from source without doing work during construction', function (): void {
    $root = sys_get_temp_dir() . '/cqrs_build_' . bin2hex(random_bytes(8));
    $reads = 0;
    $index = new CqrsDiscoveryIndex(new ClassIterator((static function () use (&$reads): Generator {
        ++$reads;
        yield BuildCommandHandler::class => new ClassInfo(BuildCommandHandler::class);
    })()));
    $builder = new CqrsBuilder($index, queryHandlers: [
        ['message' => 'query', 'service' => 'query.handler', 'method' => 'handle'],
    ]);
    try {
        expect(is_dir($root))->toBeFalse()->and($reads)->toBe(0);
        BuildFixture::build($root, $builder);
        expect(BuildFixture::read($root))->toBe([
            'command_handlers' => [BuildCommandMessage::class => ['service' => BuildCommandHandler::class, 'method' => '__invoke']],
            'query_handlers' => ['query' => ['service' => 'query.handler', 'method' => 'handle']],
            'command_listeners' => [],
        ])->and($reads)->toBe(1);
    } finally {
        BuildFixture::remove($root);
    }
});

it('preserves an earlier generation if source discovery fails', function (): void {
    $root = sys_get_temp_dir() . '/cqrs_previous_' . bin2hex(random_bytes(8));
    $failure = new RuntimeException('cannot discover');
    $index = new CqrsDiscoveryIndex(new ClassIterator((static function () use ($failure): Generator {
        throw $failure;
        yield;
    })()));
    try {
        $previous = BuildFixture::publish($root, ['command_handlers' => [], 'query_handlers' => [], 'command_listeners' => []]);
        expect(fn () => BuildFixture::build($root, new CqrsBuilder($index)))->toThrow($failure);
        expect(BuildFixture::store($root)->load()?->generation)->toBe($previous->generation);
        expect(BuildFixture::read($root))->toBe($previous->read(CqrsBuilder::ARTIFACT_ID));
    } finally {
        BuildFixture::remove($root);
    }
});

it('publishes an empty new map without modifying an already selected snapshot', function (): void {
    $root = sys_get_temp_dir() . '/cqrs_replace_' . bin2hex(random_bytes(8));
    try {
        $previous = BuildFixture::build($root, new CqrsBuilder(new CqrsDiscoveryIndex(new ClassIterator([
            new ClassInfo(BuildCommandHandler::class),
        ]))));
        $current = BuildFixture::build($root, new CqrsBuilder(new CqrsDiscoveryIndex(new ClassIterator([]))));
        expect(BuildFixture::read($root))->toBe([
            'command_handlers' => [], 'query_handlers' => [], 'command_listeners' => [],
        ]);
        expect($current->generation)->not->toBe($previous->generation);
        expect($previous->read(CqrsBuilder::ARTIFACT_ID)['command_handlers'])->toHaveKey(BuildCommandMessage::class);
    } finally {
        BuildFixture::remove($root);
    }
});

it('propagates shared store write failures without overwriting a file at its directory', function (): void {
    $root = sys_get_temp_dir() . '/cqrs_publish_failure_' . bin2hex(random_bytes(8));
    mkdir($root);
    file_put_contents($root . '/build', 'preserved');
    try {
        $builder = new CqrsBuilder(new CqrsDiscoveryIndex(new ClassIterator([])));
        expect(fn () => BuildFixture::build($root, $builder))->toThrow(ErrorException::class);
        expect(file_get_contents($root . '/build'))->toBe('preserved');
    } finally {
        BuildFixture::remove($root);
    }
});

it('preserves the published map when a discovered handler conflicts with explicit registration', function (): void {
    $root = sys_get_temp_dir() . '/cqrs_build_conflict_' . bin2hex(random_bytes(8));
    $source = static fn () => new CqrsDiscoveryIndex(new ClassIterator([new ClassInfo(BuildCommandHandler::class)]));
    try {
        $previous = BuildFixture::build($root, new CqrsBuilder($source()));
        $conflicting = new CqrsBuilder($source(), commandHandlers: [
            ['message' => BuildCommandMessage::class, 'service' => 'other.handler', 'method' => 'handle'],
        ]);
        expect(fn () => BuildFixture::build($root, $conflicting))
            ->toThrow(\Componenta\CQRS\Map\Exception\CqrsMapConflictException::class);
        expect(BuildFixture::store($root)->load()?->generation)->toBe($previous->generation)
            ->and(BuildFixture::read($root))->toBe($previous->read(CqrsBuilder::ARTIFACT_ID));
    } finally {
        BuildFixture::remove($root);
    }
});
