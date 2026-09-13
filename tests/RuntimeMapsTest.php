<?php

declare(strict_types=1);

use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey as DependencyKey;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\CQRS\App\ConfigProvider;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\CQRS\Command\CommandBusInterface;
use Componenta\CQRS\Command\Locator\CommandHandlerLocatorInterface;
use Componenta\CQRS\Query\Locator\QueryHandlerLocatorInterface;
use Componenta\DI\ContainerFactory;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;
use Componenta\Tokenizer\ClassInfo;

final readonly class RuntimeMapCommand
{
    public function __construct(public string $value)
    {
    }
}
#[AsCommandHandler]
final class RuntimeMapHandler
{
    public function __invoke(RuntimeMapCommand $command): string
    {
        return 'result:' . $command->value;
    }
}
function runtimeMapsContainer(string $root, ClassIterator $classes, array $config = [], string $environment = 'production'): ContainerValue
{
    $composition = (new ConfigFactory())->create(
        new Environment(['APP_ENV' => $environment]),
        new \Componenta\App\ConfigProvider(),
        new \Componenta\App\Console\ConfigProvider(),
        new \Componenta\CQRS\ConfigProvider(),
        new ConfigProvider(),
        static fn (): array => [
            'cqrs.map_file' => 'cqrs.php',
            ...$config,
            DependencyKey::DEPENDENCIES => [
                DependencyKey::SERVICES => [
                    PathResolverInterface::class => new PathResolver($root),
                    'app.discovery.source' => $classes,
                ],
            ],
        ],
    );
    return (new ContainerFactory())->create($composition->config, $composition->dependencies);
}
function runtimeMapsClasses(): ClassIterator
{
    return new ClassIterator([RuntimeMapHandler::class => new ClassInfo(RuntimeMapHandler::class)]);
}

it('dispatches the same public result from source or a built map in every environment', function (string $environment, bool $built): void {
    $root = sys_get_temp_dir() . '/cqrs_runtime_' . bin2hex(random_bytes(8));
    mkdir($root);
    try {
        if ($built) {
            runtimeMapsContainer($root, runtimeMapsClasses())->get(ApplicationBuildOrchestrator::class)->build();
        }
        $visited = 0;
        $classes = new ClassIterator((static function () use (&$visited): Generator {
            ++$visited;
            yield RuntimeMapHandler::class => new ClassInfo(RuntimeMapHandler::class);
        })());
        $container = runtimeMapsContainer($root, $classes, environment: $environment);
        $bus = $container->get(CommandBusInterface::class);
        expect($bus->dispatch(new RuntimeMapCommand('first'))->result?->value)->toBe('result:first');
        expect($bus->dispatch(new RuntimeMapCommand('second'))->result?->value)->toBe('result:second');
        $container->get(QueryHandlerLocatorInterface::class);
        expect($visited)->toBe($built ? 0 : 1);
        expect(is_file($root . '/cqrs.php'))->toBe($built);
    } finally {
        if (is_file($root . '/cqrs.php')) {
            unlink($root . '/cqrs.php');
        }
        rmdir($root);
    }
})->with(['development', 'production', 'staging'])->with([false, true]);

it('falls back from an invalid map without writing or replacing it', function (string $contents): void {
    $root = sys_get_temp_dir() . '/cqrs_invalid_' . bin2hex(random_bytes(8));
    mkdir($root);
    file_put_contents($root . '/cqrs.php', $contents);
    try {
        $container = runtimeMapsContainer($root, runtimeMapsClasses());
        expect($container->get(CommandBusInterface::class)->dispatch(new RuntimeMapCommand('fallback'))->result?->value)->toBe('result:fallback');
        expect(file_get_contents($root . '/cqrs.php'))->toBe($contents)
            ->and(array_map('basename', glob($root . '/*')))->toBe(['cqrs.php']);
    } finally {
        unlink($root . '/cqrs.php');
        rmdir($root);
    }
})->with([
    'syntax' => ['<?php broken syntax'],
    'extra section' => ["<?php return ['command_handlers' => [], 'query_handlers' => [], 'command_listeners' => [], 'version' => 3];"],
    'not an array' => ['<?php return null;'],
    'missing section' => ["<?php return ['command_handlers' => []];"],
    'invalid handler' => ["<?php return ['command_handlers' => ['c' => ['service' => '']], 'query_handlers' => [], 'command_listeners' => []];"],
]);

it('rebuilds from source even after the runtime has loaded an older valid map', function (): void {
    $root = sys_get_temp_dir() . '/cqrs_rebuild_' . bin2hex(random_bytes(8));
    mkdir($root);
    file_put_contents($root . '/cqrs.php', "<?php return ['command_handlers' => [], 'query_handlers' => [], 'command_listeners' => []];");
    try {
        $container = runtimeMapsContainer($root, runtimeMapsClasses());
        expect($container->get(CommandHandlerLocatorInterface::class)->supports(new RuntimeMapCommand('old')))->toBeFalse();
        $container->get(ApplicationBuildOrchestrator::class)->build();
        $fresh = runtimeMapsContainer($root, new ClassIterator([]));
        expect($fresh->get(CommandBusInterface::class)->dispatch(new RuntimeMapCommand('new'))->result?->value)->toBe('result:new');
    } finally {
        unlink($root . '/cqrs.php');
        rmdir($root);
    }
});

it('keeps list and help lazy and runs app:build through the same container', function (): void {
    $root = sys_get_temp_dir() . '/cqrs_console_' . bin2hex(random_bytes(8));
    mkdir($root);
    $visited = 0;
    $classes = new ClassIterator((static function () use (&$visited): Generator {
        ++$visited;
        yield RuntimeMapHandler::class => new ClassInfo(RuntimeMapHandler::class);
    })());
    try {
        $container = runtimeMapsContainer($root, $classes);
        $application = new \Symfony\Component\Console\Application();
        $application->setAutoExit(false);
        $command = $container->get(\Componenta\App\Console\Command\BuildCommand::class);
        $application->addCommand($command);
        $tester = new \Symfony\Component\Console\Tester\ApplicationTester($application);
        expect($tester->run(['command' => 'list']))->toBe(0);
        expect($tester->getDisplay())->toContain('app:build');
        expect($tester->run(['command' => 'help', 'command_name' => 'app:build']))->toBe(0);
        expect($visited)->toBe(0)->and(is_file($root . '/cqrs.php'))->toBeFalse();
        expect($tester->run(['command' => 'app:build']))->toBe(0);
        expect($visited)->toBe(1)->and(is_file($root . '/cqrs.php'))->toBeTrue();
    } finally {
        if (is_file($root . '/cqrs.php')) {
            unlink($root . '/cqrs.php');
        }
        rmdir($root);
    }
});

it('reports a nonzero command status when a CQRS builder cannot read source', function (): void {
    $root = sys_get_temp_dir() . '/cqrs_console_failure_' . bin2hex(random_bytes(8));
    mkdir($root);
    try {
        $classes = new ClassIterator((static function (): Generator {
            throw new RuntimeException('discovery unavailable');
            yield;
        })());
        $container = runtimeMapsContainer($root, $classes);
        $application = new \Symfony\Component\Console\Application();
        $application->setAutoExit(false);
        $application->addCommand($container->get(\Componenta\App\Console\Command\BuildCommand::class));
        $tester = new \Symfony\Component\Console\Tester\ApplicationTester($application);
        expect($tester->run(['command' => 'app:build']))->toBe(1);
        expect($tester->getDisplay())->toContain('discovery unavailable');
        expect(is_file($root . '/cqrs.php'))->toBeFalse();
    } finally {
        rmdir($root);
    }
});

it('does not create runtime cache directories when the artifact is missing', function (): void {
    $root = sys_get_temp_dir() . '/cqrs_readonly_' . bin2hex(random_bytes(8));
    $container = runtimeMapsContainer($root, runtimeMapsClasses(), ['cqrs.map_file' => 'missing/nested/cqrs.php']);
    expect($container->get(CommandBusInterface::class)->dispatch(new RuntimeMapCommand('read'))->result?->value)->toBe('result:read');
    expect(is_dir($root))->toBeFalse();
});

it('propagates source registration conflicts even when artifact loading falls back', function (): void {
    $root = sys_get_temp_dir() . '/cqrs_conflict_' . bin2hex(random_bytes(8));
    $container = runtimeMapsContainer($root, runtimeMapsClasses(), ['cqrs.command_handlers' => [
        ['message' => RuntimeMapCommand::class, 'service' => 'other', 'method' => '__invoke'],
    ]]);
    expect(fn () => $container->get(CommandBusInterface::class))
        ->toThrow(\Componenta\DI\Exception\ResolutionException::class, RuntimeMapCommand::class);
    expect(is_dir($root))->toBeFalse();
});


final class RuntimeMapTrace
{
    public array $events = [];
}

#[\Componenta\CQRS\Command\Attribute\AsCommandListener(RuntimeMapCommand::class, priority: 10)]
#[\Componenta\CQRS\Command\Attribute\AsCommandListener(RuntimeMapCommand::class, priority: 10)]
final readonly class RuntimeMapFirstListener implements \Componenta\CQRS\Command\Event\CommandListenerInterface
{
    public function __construct(private RuntimeMapTrace $trace)
    {
    }
    public function handleEvent(\Componenta\CQRS\Command\Event\CommandProcessEvent|\Componenta\CQRS\Command\Event\CommandProcessedEvent|\Componenta\CQRS\Command\Event\CommandFailedEvent $event): void
    {
        $this->trace->events[] = ['first', $event::class];
    }
}

#[\Componenta\CQRS\Command\Attribute\AsCommandListener(RuntimeMapCommand::class, eventTypes: [\Componenta\CQRS\Command\Event\CommandProcessedEvent::class])]
final readonly class RuntimeMapLastListener implements \Componenta\CQRS\Command\Event\CommandListenerInterface
{
    public function __construct(private RuntimeMapTrace $trace)
    {
    }
    public function handleEvent(\Componenta\CQRS\Command\Event\CommandProcessEvent|\Componenta\CQRS\Command\Event\CommandProcessedEvent|\Componenta\CQRS\Command\Event\CommandFailedEvent $event): void
    {
        $this->trace->events[] = ['last', $event::class];
    }
}

#[\Componenta\CQRS\Command\Attribute\AsCommandListener(RuntimeMapCommand::class, eventTypes: [\Componenta\CQRS\Command\Event\CommandFailedEvent::class])]
final readonly class RuntimeMapFailureListener implements \Componenta\CQRS\Command\Event\CommandListenerInterface
{
    public function __construct()
    {
        throw new RuntimeException('A filtered listener must not be created.');
    }
    public function handleEvent(\Componenta\CQRS\Command\Event\CommandProcessEvent|\Componenta\CQRS\Command\Event\CommandProcessedEvent|\Componenta\CQRS\Command\Event\CommandFailedEvent $event): void
    {
    }
}

it('keeps listener deduplication, priority and event filtering equivalent with a built map', function (bool $built): void {
    $root = sys_get_temp_dir() . '/cqrs_listeners_' . bin2hex(random_bytes(8));
    mkdir($root);
    $names = [RuntimeMapLastListener::class, RuntimeMapFailureListener::class, RuntimeMapHandler::class, RuntimeMapFirstListener::class];
    $classes = new ClassIterator(array_combine($names, array_map(static fn (string $name) => new ClassInfo($name), $names)));
    $config = [
        \Componenta\CQRS\ConfigKey::COMMAND_MIDDLEWARES => [\Componenta\CQRS\Command\Middleware\EventMiddleware::class],
        'cqrs.command_listeners' => [
            ['message' => RuntimeMapCommand::class, 'service' => RuntimeMapFirstListener::class, 'priority' => 10],
        ],
    ];
    try {
        if ($built) {
            runtimeMapsContainer($root, $classes, $config)->get(ApplicationBuildOrchestrator::class)->build();
        }
        $container = runtimeMapsContainer($root, $built ? new ClassIterator([]) : $classes, $config);
        $result = $container->get(CommandBusInterface::class)->dispatch(new RuntimeMapCommand('listeners'));

        expect($result->result?->value)->toBe('result:listeners');
        expect($container->get(RuntimeMapTrace::class)->events)->toBe([
            ['first', \Componenta\CQRS\Command\Event\CommandProcessEvent::class],
            ['first', \Componenta\CQRS\Command\Event\CommandProcessedEvent::class],
            ['last', \Componenta\CQRS\Command\Event\CommandProcessedEvent::class],
        ]);
    } finally {
        if (is_file($root . '/cqrs.php')) {
            unlink($root . '/cqrs.php');
        }
        rmdir($root);
    }
})->with([false, true]);

it('rejects invalid explicit registration even when the artifact is valid', function (): void {
    $root = sys_get_temp_dir() . '/cqrs_config_error_' . bin2hex(random_bytes(8));
    mkdir($root);
    try {
        runtimeMapsContainer($root, runtimeMapsClasses())->get(ApplicationBuildOrchestrator::class)->build();
        $container = runtimeMapsContainer($root, new ClassIterator([]), ['cqrs.command_handlers' => 'invalid']);
        expect(fn () => $container->get(CommandBusInterface::class))
            ->toThrow(\Componenta\DI\Exception\ResolutionException::class, 'cqrs.command_handlers');
    } finally {
        unlink($root . '/cqrs.php');
        rmdir($root);
    }
});

final readonly class RuntimeExplicitQuery
{
    public function __construct(public string $value)
    {
    }
}
final readonly class RuntimeExplicitQueryHandler
{
    public function __invoke(RuntimeExplicitQuery $query): string
    {
        return 'query:' . $query->value;
    }
}

it('merges explicit queries and listeners with discovered command handlers in both modes', function (bool $built): void {
    $root = sys_get_temp_dir() . '/cqrs_explicit_' . bin2hex(random_bytes(8));
    mkdir($root);
    $config = [
        \Componenta\CQRS\ConfigKey::COMMAND_MIDDLEWARES => [\Componenta\CQRS\Command\Middleware\EventMiddleware::class],
        'cqrs.query_handlers' => [
            ['message' => RuntimeExplicitQuery::class, 'service' => RuntimeExplicitQueryHandler::class, 'method' => '__invoke'],
        ],
        'cqrs.command_listeners' => [
            ['message' => RuntimeMapCommand::class, 'service' => RuntimeMapFirstListener::class],
        ],
    ];
    try {
        if ($built) {
            runtimeMapsContainer($root, runtimeMapsClasses(), $config)->get(ApplicationBuildOrchestrator::class)->build();
        }
        $container = runtimeMapsContainer($root, $built ? new ClassIterator([]) : runtimeMapsClasses(), $config);
        expect($container->get(\Componenta\CQRS\Query\QueryBusInterface::class)->handle(new RuntimeExplicitQuery('read')))
            ->toBe('query:read');
        expect($container->get(CommandBusInterface::class)->dispatch(new RuntimeMapCommand('write'))->result?->value)
            ->toBe('result:write');
        expect($container->get(RuntimeMapTrace::class)->events)->toBe([
            ['first', \Componenta\CQRS\Command\Event\CommandProcessEvent::class],
            ['first', \Componenta\CQRS\Command\Event\CommandProcessedEvent::class],
        ]);
    } finally {
        if (is_file($root . '/cqrs.php')) {
            unlink($root . '/cqrs.php');
        }
        rmdir($root);
    }
})->with([false, true]);

final class RuntimeNumericMessage implements \Componenta\CQRS\Command\NamedCommandInterface, \Componenta\CQRS\Query\NamedQueryInterface
{
    public function __construct(public string $commandName)
    {
    }
    public string $queryName { get => $this->commandName; }
}
#[AsCommandHandler('123')]
final class RuntimeNumericCommandHandler
{
    public function __invoke(RuntimeNumericMessage $message): string
    {
        return 'command:' . $message->commandName;
    }
}
#[\Componenta\CQRS\Query\Attribute\AsQueryHandler('123')]
final class RuntimeNumericQueryHandler
{
    public function __invoke(RuntimeNumericMessage $message): string
    {
        return 'query:' . $message->queryName;
    }
}
#[\Componenta\CQRS\Command\Attribute\AsCommandListener('123', eventTypes: [\Componenta\CQRS\Command\Event\CommandProcessEvent::class])]
final class RuntimeNumericListener implements \Componenta\CQRS\Command\Event\CommandListenerInterface
{
    public array $names = [];
    public function handleEvent(\Componenta\CQRS\Command\Event\CommandProcessEvent|\Componenta\CQRS\Command\Event\CommandProcessedEvent|\Componenta\CQRS\Command\Event\CommandFailedEvent $event): void
    {
        $this->names[] = $event->operation->command->commandName;
    }
}

it('merges numeric message names and preserves dispatch after building the map', function (bool $built): void {
    $root = sys_get_temp_dir() . '/cqrs_numeric_' . bin2hex(random_bytes(8));
    mkdir($root);
    $classes = [RuntimeNumericCommandHandler::class, RuntimeNumericQueryHandler::class, RuntimeNumericListener::class];
    $source = new ClassIterator(array_combine($classes, array_map(static fn (string $class) => new ClassInfo($class), $classes)));
    $names = ['0', '123', '-1', '001'];
    $config = [
        \Componenta\CQRS\ConfigKey::COMMAND_MIDDLEWARES => [\Componenta\CQRS\Command\Middleware\EventMiddleware::class],
        'cqrs.command_handlers' => array_map(static fn (string $name): array => ['message' => $name, 'service' => RuntimeNumericCommandHandler::class, 'method' => '__invoke'], $names),
        'cqrs.query_handlers' => array_map(static fn (string $name): array => ['message' => $name, 'service' => RuntimeNumericQueryHandler::class, 'method' => '__invoke'], $names),
        'cqrs.command_listeners' => array_map(static fn (string $name): array => ['message' => $name, 'service' => RuntimeNumericListener::class, 'events' => [\Componenta\CQRS\Command\Event\CommandProcessEvent::class]], $names),
    ];
    try {
        if ($built) {
            runtimeMapsContainer($root, $source, $config)->get(ApplicationBuildOrchestrator::class)->build();
        }
        $container = runtimeMapsContainer($root, $built ? new ClassIterator([]) : $source, $config);
        foreach ($names as $name) {
            $message = new RuntimeNumericMessage($name);
            expect($container->get(CommandBusInterface::class)->dispatch($message)->result?->value)->toBe('command:' . $name)
                ->and($container->get(\Componenta\CQRS\Query\QueryBusInterface::class)->handle($message))->toBe('query:' . $name);
        }
        expect($container->get(RuntimeNumericListener::class)->names)->toBe($names);
    } finally {
        if (is_file($root . '/cqrs.php')) {
            unlink($root . '/cqrs.php');
        }
        rmdir($root);
    }
})->with([false, true]);
