<?php

declare(strict_types=1);

use Componenta\Config\ConfigLoader;
use Componenta\Config\Environment;
use Componenta\CQRS\App\Compile\CqrsMapCompiler;
use Componenta\CQRS\App\ConfigProvider as CqrsAppConfigProvider;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\CQRS\Command\Attribute\AsCommandListener;
use Componenta\CQRS\Command\Event\CommandFailedEvent;
use Componenta\CQRS\Command\Event\CommandListenerInterface;
use Componenta\CQRS\Command\Event\CommandProcessedEvent;
use Componenta\CQRS\Command\Event\CommandProcessEvent;
use Componenta\CQRS\Command\Locator\CommandHandlerLocatorInterface;
use Componenta\CQRS\Command\Locator\CommandListenersLocatorInterface;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\ConfigProvider as CqrsConfigProvider;
use Componenta\CQRS\Map\CqrsMapProviderInterface;
use Componenta\CQRS\Query\Attribute\AsQueryHandler;
use Componenta\CQRS\Query\Locator\QueryHandlerLocatorInterface;
use Componenta\DI\ConfigKey as DiConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\Tokenizer\ClassInfo;

final readonly class RoundTripCommand
{
    public function __construct(public string $value)
    {
    }
}

final readonly class RoundTripQuery
{
    public function __construct(public string $value)
    {
    }
}

#[AsCommandHandler]
final readonly class RoundTripCommandHandler
{
    public function __invoke(RoundTripCommand $command): string
    {
        return 'command:' . $command->value;
    }
}

#[AsQueryHandler]
final readonly class RoundTripQueryHandler
{
    public function __invoke(RoundTripQuery $query): string
    {
        return 'query:' . $query->value;
    }
}

#[AsCommandListener(
    RoundTripCommand::class,
    eventTypes: [CommandProcessedEvent::class],
    priority: 10,
)]
final class RoundTripListener implements CommandListenerInterface
{
    /** @var list<class-string> */
    public static array $events = [];

    public function handleEvent(
        CommandProcessEvent|CommandProcessedEvent|CommandFailedEvent $event,
    ): void {
        self::$events[] = $event::class;
    }
}

it('round-trips the effective development map into production dispatch', function (): void {
    $developmentConfig = ConfigLoader::load(
        new Environment(['APP_ENV' => 'development']),
        new CqrsConfigProvider(),
        new CqrsAppConfigProvider(),
    );
    $development = ContainerBuilder::configure($developmentConfig)->build();
    $index = $development->get(CqrsDiscoveryIndex::class);

    foreach ([
        RoundTripCommandHandler::class,
        RoundTripQueryHandler::class,
        RoundTripListener::class,
    ] as $class) {
        $index->handle(new ClassInfo($class));
    }

    $index->finalize();
    $developmentMapProvider = $development->get(CqrsMapProviderInterface::class);
    $developmentMap = $developmentMapProvider->map()->toArray();
    $result = (new CqrsMapCompiler($developmentMapProvider))->compile($index, '');

    $compiledMap = $result->configValue;
    $compiledProvider = static fn(): array => [
        ConfigKey::CQRS_MAP => $compiledMap,
    ];
    $cacheFile = tempnam(sys_get_temp_dir(), 'cqrs-config-cache-');

    if ($cacheFile === false) {
        throw new RuntimeException('Unable to create CQRS config cache test file.');
    }

    $factoryDirectory = $cacheFile . '.factories';
    $environment = new Environment(['APP_ENV' => 'production']);

    try {
        $sourceProductionConfig = ConfigLoader::load(
            $environment,
            new CqrsConfigProvider(),
            new CqrsAppConfigProvider(),
            $compiledProvider,
        );
        $factoryBuilder = ContainerBuilder::configure($sourceProductionConfig);
        $factories = $factoryBuilder
            ->compileFactories($index->entries(), $factoryDirectory);
        $cacheConfig = ConfigLoader::load(
            $environment,
            new CqrsConfigProvider(),
            new CqrsAppConfigProvider(),
            $compiledProvider,
        );
        ConfigLoader::export($cacheConfig, $cacheFile);

        $rawCache = require $cacheFile;
        $productionConfig = ConfigLoader::loadFromFile($cacheFile);
        $productionDependencies = $sourceProductionConfig->get(DiConfigKey::DEPENDENCIES);
        $productionDependencies[DiConfigKey::FACTORIES] = array_replace(
            $factories,
            $productionDependencies[DiConfigKey::FACTORIES] ?? [],
        );
        $productionInvokables = $productionDependencies[DiConfigKey::INVOKABLES] ?? [];
        foreach ($factoryBuilder->invokables as $class) {
            if (!in_array($class, $productionInvokables, true)) {
                $productionInvokables[] = $class;
            }
        }
        $productionDependencies[DiConfigKey::INVOKABLES] = $productionInvokables;

        $production = ContainerBuilder::configureFromCache(
            $productionConfig,
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                DiConfigKey::DEPENDENCIES => $productionDependencies,
            ],
            $factoryDirectory,
        )->build();
        $command = new RoundTripCommand('compiled');
        $query = new RoundTripQuery('compiled');
        $commandHandler = $production
            ->get(CommandHandlerLocatorInterface::class)
            ->locateFor($command);
        $queryHandler = $production
            ->get(QueryHandlerLocatorInterface::class)
            ->locateFor($query);
        RoundTripListener::$events = [];
        $listeners = $production->get(CommandListenersLocatorInterface::class);
        $event = new CommandProcessedEvent(
            Operation::create($command)->withResult(
                new \Componenta\CQRS\Command\OperationResult('done'),
            ),
        );

        foreach ($listeners->locateFor($event) as $listener) {
            $listener->handleEvent($event);
        }

        expect($result->configKey)->toBe(ConfigKey::CQRS_MAP)
            ->and($compiledMap)->toBe($developmentMap)
            ->and($rawCache['config'][ConfigKey::CQRS_MAP])->toBe($compiledMap)
            ->and($production->get(CqrsMapProviderInterface::class)->map()->toArray())
            ->toBe($developmentMap)
            ->and($commandHandler($command))->toBe('command:compiled')
            ->and($queryHandler($query))->toBe('query:compiled')
            ->and(RoundTripListener::$events)->toBe([CommandProcessedEvent::class]);
    } finally {
        foreach (glob($factoryDirectory . '/container.factories.*.php') ?: [] as $factoryFile) {
            @unlink($factoryFile);
        }
        if (is_dir($factoryDirectory)) {
            @rmdir($factoryDirectory);
        }
        @unlink($cacheFile);
    }
});
