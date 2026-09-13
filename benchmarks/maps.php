<?php

declare(strict_types=1);

use Componenta\ClassFinder\ClassIterator;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\CQRS\App\Build\CqrsBuilder;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\App\Factory\CqrsMapsFactory;
use Componenta\CQRS\Command\Locator\CommandHandlerLocator;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;
use Componenta\Tokenizer\ClassInfo;
use Psr\Container\ContainerInterface;

require dirname(__DIR__) . '/tests/bootstrap.php';

$count = isset($argv[1]) ? filter_var($argv[1], FILTER_VALIDATE_INT) : 500;
if (!is_int($count) || $count < 1 || $count > 5000) {
    throw new InvalidArgumentException('Handler count must be between 1 and 5000.');
}

$names = [];
for ($number = 0; $number < $count; ++$number) {
    eval(sprintf(
        'final class CqrsBenchCommand%d {} #[\Componenta\CQRS\Command\Attribute\AsCommandHandler] final class CqrsBenchHandler%d { public function __invoke(CqrsBenchCommand%d $command): int { return %d; } }',
        $number,
        $number,
        $number,
        $number,
    ));
    $names[] = 'CqrsBenchHandler' . $number;
}

function indexForBenchmark(array $names): CqrsDiscoveryIndex
{
    return new CqrsDiscoveryIndex(new ClassIterator((static function () use ($names): Generator {
        foreach ($names as $name) {
            yield $name => new ClassInfo($name);
        }
    })()));
}

function medianMicros(Closure $work, int $samples, int $repetitions = 1): float
{
    $times = [];
    for ($sample = 0; $sample < $samples; ++$sample) {
        $start = hrtime(true);
        for ($iteration = 0; $iteration < $repetitions; ++$iteration) {
            $work();
        }
        $times[] = (hrtime(true) - $start) / 1000 / $repetitions;
    }
    sort($times);
    return $times[intdiv(count($times), 2)];
}

$root = sys_get_temp_dir() . '/cqrs_benchmark_' . bin2hex(random_bytes(8));
mkdir($root);
$file = $root . '/cqrs.php';
try {
    (new CqrsBuilder(indexForBenchmark($names), $file))->build();
    $factory = new CqrsMapsFactory();
    $container = new class () implements ContainerInterface {
        public array $services = [];
        public function get(string $id): mixed
        {
            return $this->services[$id] ?? throw new RuntimeException($id);
        }
        public function has(string $id): bool
        {
            return array_key_exists($id, $this->services);
        }
    };
    $container->services[PathResolverInterface::class] = new PathResolver($root);
    $container->services[CqrsDiscoveryIndex::class] = indexForBenchmark($names);
    $environment = new Environment([]);
    $source = new ContainerValue($container, new Config(['cqrs.map_file' => 'absent.php'], $environment));
    $built = new ContainerValue($container, new Config(['cqrs.map_file' => 'cqrs.php'], $environment));
    $sourceMicros = medianMicros(static function () use ($container, $names, $factory, $source): void {
        $container->services[CqrsDiscoveryIndex::class] = indexForBenchmark($names);
        $factory($source);
    }, 31);
    $fileMicros = medianMicros(static function () use ($factory, $built): void {
        $factory($built);
    }, 31);

    $maps = $factory($built);
    $container->services[CqrsBenchHandler0::class] = new CqrsBenchHandler0();
    $locator = new CommandHandlerLocator($maps['command_handlers'], $container);
    $command = new CqrsBenchCommand0();
    if ($locator->locateFor($command)($command) !== 0) {
        throw new RuntimeException('Benchmark handler did not execute.');
    }
    $lookupMicros = medianMicros(static function () use ($locator, $command): void {
        $locator->locateFor($command)($command);
    }, 11, 10000);

    echo json_encode([
        'php' => PHP_VERSION,
        'handlers' => $count,
        'opcache_cli' => filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL),
        'samples' => 31,
        'source_factory_median_us' => round($sourceMicros, 2),
        'file_factory_median_us' => round($fileMicros, 2),
        'warm_locate_and_call_median_us' => round($lookupMicros, 3),
        'source_to_file_ratio' => round($sourceMicros / $fileMicros, 2),
        'scope' => 'Fresh discovery index or PHP file per factory call; loaded fixture classes, no filesystem scanning/tokenization or DI construction. Warm calls use the same locator implementation in both modes.',
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    if (is_file($file)) {
        unlink($file);
    }
    rmdir($root);
}
