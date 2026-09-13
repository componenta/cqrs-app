<?php

declare(strict_types=1);

use Componenta\ClassFinder\ClassIterator;
use Componenta\CQRS\App\Build\CqrsBuilder;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\Tokenizer\ClassInfo;

final class BuildCommandMessage
{
}
#[AsCommandHandler]
final class BuildCommandHandler
{
    public function __invoke(BuildCommandMessage $command): void
    {
    }
}

it('builds a complete map from source without doing work during construction', function (): void {
    $root = sys_get_temp_dir() . '/cqrs_build_' . bin2hex(random_bytes(8));
    $file = $root . '/nested/cqrs.php';
    $reads = 0;
    $index = new CqrsDiscoveryIndex(new ClassIterator((static function () use (&$reads): Generator {
        ++$reads;
        yield BuildCommandHandler::class => new ClassInfo(BuildCommandHandler::class);
    })()));
    $builder = new CqrsBuilder($index, $file, queryHandlers: [
        ['message' => 'query', 'service' => 'query.handler', 'method' => 'handle'],
    ]);
    try {
        expect(is_dir($root))->toBeFalse()->and($reads)->toBe(0);
        $builder->build();
        expect(require $file)->toBe([
            'command_handlers' => [BuildCommandMessage::class => ['service' => BuildCommandHandler::class, 'method' => '__invoke']],
            'query_handlers' => ['query' => ['service' => 'query.handler', 'method' => 'handle']],
            'command_listeners' => [],
        ])->and($reads)->toBe(1)
          ->and(array_map('basename', glob($root . '/nested/*')))->toBe(['cqrs.php']);
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir($root . '/nested')) {
            rmdir($root . '/nested');
        }
        if (is_dir($root)) {
            rmdir($root);
        }
    }
});

it('preserves an earlier artifact if source discovery fails', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'cqrs_previous_');
    file_put_contents($file, 'previous artifact');
    $failure = new RuntimeException('cannot discover');
    $index = new CqrsDiscoveryIndex(new ClassIterator((static function () use ($failure): Generator {
        throw $failure;
        yield;
    })()));
    try {
        expect(fn () => (new CqrsBuilder($index, $file))->build())->toThrow($failure);
        expect(file_get_contents($file))->toBe('previous artifact');
    } finally {
        unlink($file);
    }
});

it('replaces an existing artifact with a complete new map', function (): void {
    $root = sys_get_temp_dir() . '/cqrs_replace_' . bin2hex(random_bytes(8));
    mkdir($root);
    $file = $root . '/cqrs.php';
    file_put_contents($file, '<?php return null;');
    try {
        (new CqrsBuilder(new CqrsDiscoveryIndex(new ClassIterator([])), $file))->build();
        expect(require $file)->toBe([
            'command_handlers' => [],
            'query_handlers' => [],
            'command_listeners' => [],
        ]);
        expect(array_map('basename', glob($root . '/*')))->toBe(['cqrs.php']);
    } finally {
        unlink($file);
        rmdir($root);
    }
});

it('cleans temporary files when publishing fails and preserves the destination', function (): void {
    $root = sys_get_temp_dir() . '/cqrs_publish_failure_' . bin2hex(random_bytes(8));
    mkdir($root . '/cqrs.php', 0o755, true);
    file_put_contents($root . '/cqrs.php/existing', 'preserved');
    try {
        $builder = new CqrsBuilder(new CqrsDiscoveryIndex(new ClassIterator([])), $root . '/cqrs.php');
        expect(fn () => $builder->build())->toThrow(ErrorException::class, 'rename');
        expect(file_get_contents($root . '/cqrs.php/existing'))->toBe('preserved');
        expect(glob($root . '/.*.tmp'))->toBe([]);
    } finally {
        unlink($root . '/cqrs.php/existing');
        rmdir($root . '/cqrs.php');
        rmdir($root);
    }
});
