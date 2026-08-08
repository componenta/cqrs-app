<?php

declare(strict_types=1);

use Componenta\CQRS\App\Compile\CqrsMapCompiler;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Map\CqrsMap;
use Componenta\Tokenizer\ClassInfo;

final readonly class CompilerTestCommand
{
}

#[AsCommandHandler]
final readonly class CompilerTestHandler
{
    public function __invoke(CompilerTestCommand $command): void
    {
    }
}

it('compiles one compact versioned map from a finalized discovery index', function (): void {
    $index = new CqrsDiscoveryIndex();
    $index->handle(new ClassInfo(CompilerTestHandler::class));
    $index->finalize();
    $compiler = new CqrsMapCompiler();

    $result = $compiler->compile($index, 'unused');

    expect($compiler->supports($index))->toBeTrue()
        ->and($compiler->supports(new stdClass()))->toBeFalse()
        ->and($result->configKey)->toBe(ConfigKey::CQRS_MAP)
        ->and($result->configValue)->toBe([
            'version' => CqrsMap::VERSION,
            'commands' => [
                'handlers' => [
                    CompilerTestCommand::class => [
                        'service' => CompilerTestHandler::class,
                        'method' => '__invoke',
                    ],
                ],
                'known' => [
                    CompilerTestCommand::class => true,
                ],
            ],
        ])
        ->and($result->files)->toBe([]);
});

it('emits only the version for an empty map instead of empty sections', function (): void {
    $index = new CqrsDiscoveryIndex();
    $index->finalize();

    $result = (new CqrsMapCompiler())->compile($index, '');

    expect($result->configKey)->toBe(ConfigKey::CQRS_MAP)
        ->and($result->configValue)->toBe(['version' => CqrsMap::VERSION]);
});

it('does not finalize discovery implicitly and rejects unsupported listeners', function (): void {
    $index = new CqrsDiscoveryIndex();
    $compiler = new CqrsMapCompiler();

    expect(fn() => $compiler->compile($index, ''))
        ->toThrow(LogicException::class, 'not finalized')
        ->and($index->finalized)->toBeFalse()
        ->and(fn() => $compiler->compile(new stdClass(), ''))
        ->toThrow(InvalidArgumentException::class, 'supports only');
});
