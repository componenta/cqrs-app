<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey as ConfigMergeKey;
use Componenta\CQRS\App\Compile\CqrsMapCompiler;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\App\Map\DiscoveryCqrsMapProvider;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\CQRS\Command\Event\CommandProcessedEvent;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Map\CommandListenerDescriptor;
use Componenta\CQRS\Map\CommandMetadataDescriptor;
use Componenta\CQRS\Map\CompositeCqrsMapProvider;
use Componenta\CQRS\Map\CqrsMap;
use Componenta\CQRS\Map\CqrsMapProviderInterface;
use Componenta\CQRS\Map\HandlerDescriptor;
use Componenta\Tokenizer\ClassInfo;

use function Componenta\Config\config_merge;

final readonly class CompilerTestCommand {}

#[AsCommandHandler]
final readonly class CompilerTestHandler
{
    public function __invoke(CompilerTestCommand $command): void {}
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class CompilerTestMetadata
{
    public function __construct(
        public int $attempts,
        public int $delay,
    ) {}
}

final readonly class CompilerTestMapProvider implements CqrsMapProviderInterface
{
    public function __construct(private CqrsMap $map = new CqrsMap()) {}

    public function map(): CqrsMap
    {
        return $this->map;
    }
}

function compilerTestIndex(): CqrsDiscoveryIndex
{
    $index = new CqrsDiscoveryIndex();
    $index->handle(new ClassInfo(CompilerTestHandler::class));
    $index->finalize();

    return $index;
}

function compilerTestEffectiveProvider(
    CqrsDiscoveryIndex $index,
    ?CqrsMap $configured = null,
): CqrsMapProviderInterface {
    return new CompositeCqrsMapProvider(
        new CompilerTestMapProvider($configured ?? CqrsMap::empty()),
        new DiscoveryCqrsMapProvider($index),
    );
}

it('compiles the effective runtime map from a finalized discovery index', function (): void {
    $index = compilerTestIndex();
    $compiler = new CqrsMapCompiler(compilerTestEffectiveProvider($index));

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

it('emits only the version for an empty effective map', function (): void {
    $index = new CqrsDiscoveryIndex();
    $index->finalize();
    $provider = new CompilerTestMapProvider();

    $result = (new CqrsMapCompiler($provider))->compile($index, '');

    expect($result->configKey)->toBe(ConfigKey::CQRS_MAP)
        ->and($result->configValue)->toBe(['version' => CqrsMap::VERSION]);
});

it('does not finalize discovery implicitly and rejects unsupported listeners', function (): void {
    $index = new CqrsDiscoveryIndex();
    $compiler = new CqrsMapCompiler(new DiscoveryCqrsMapProvider($index));

    expect(fn() => $compiler->compile($index, ''))
        ->toThrow(LogicException::class, 'not finalized')
        ->and($index->finalized)->toBeFalse()
        ->and(fn() => $compiler->compile(new stdClass(), ''))
        ->toThrow(InvalidArgumentException::class, 'supports only');
});

it('marks a full effective map so generic build merging replaces numeric indexes', function (): void {
    $configured = new CqrsMap(
        commandHandlers: [
            'manual.command' => new HandlerDescriptor('manual.handler', 'handle'),
        ],
        commandListeners: [
            'manual.command' => [
                new CommandListenerDescriptor(
                    'manual.listener',
                    [CommandProcessedEvent::class],
                    10,
                ),
            ],
        ],
        commandMetadata: [
            'manual.command' => [
                CompilerTestMetadata::class => new CommandMetadataDescriptor(
                    CompilerTestMetadata::class,
                    [3, 100],
                ),
            ],
        ],
    );
    $index = compilerTestIndex();
    $provider = compilerTestEffectiveProvider($index, $configured);
    $result = (new CqrsMapCompiler(
        $provider,
        configuredMapPresent: true,
    ))->compile($index, '');

    expect($result->configValue[ConfigMergeKey::OVERRIDE_INDEXES])->toBeTrue();

    $compiledArtifact = config_merge(
        $configured->toArray(),
        $result->configValue,
    );
    $compiledMap = CqrsMap::fromArray($compiledArtifact);

    expect($compiledMap->toArray())->toBe($provider->map()->toArray())
        ->and($compiledMap->commandHandler('manual.command')?->service)
        ->toBe('manual.handler')
        ->and($compiledMap->commandHandler(CompilerTestCommand::class)?->service)
        ->toBe(CompilerTestHandler::class)
        ->and($compiledMap->commandListeners('manual.command'))->toHaveCount(1)
        ->and($compiledMap->commandMetadata(
            'manual.command',
            CompilerTestMetadata::class,
        )?->arguments)->toBe([3, 100]);
});
