<?php

declare(strict_types=1);
use PHPUnit\Framework\Assert;
use Componenta\App\Config\ConfigDefinition;
use Componenta\App\Config\DiscoveryDefinition;
use Componenta\App\Config\ConfigFactory;
use Componenta\App\Runner;
use Componenta\App\Scope;
use Componenta\App\Console\IOFactory;
use Componenta\App\Console\InputFactoryInterface;
use Componenta\App\Console\OutputFactoryInterface;
use Componenta\Config\Environment;
use Componenta\Config\ConfigKey as DIKey;
use Componenta\CQRS\App\Build\CqrsBuilder;
use Componenta\CQRS\Command\CommandBusInterface;
use Componenta\CQRS\Query\QueryBusInterface;
use Componenta\DI\ContainerFactory;
use Componenta\Stdlib\PathResolver;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

it('runs ordinary app build with shared filesystem discovery and lazy builders in every environment', function (): void {
    $root = sys_get_temp_dir().'/cqrs_runner_audit_'.bin2hex(random_bytes(8));
    mkdir($root.'/src', 0755, true);
    $fixture = $root.'/src/Messages.php';
    file_put_contents($fixture, <<<'PHP'
<?php
namespace AuditFs;
final readonly class Message { public function __construct(public string $value) {} }
#[\Componenta\CQRS\Command\Attribute\AsCommandHandler]
#[\Componenta\CQRS\Query\Attribute\AsQueryHandler]
final class Handler { public function __invoke(Message $message): string { return 'filesystem:'.$message->value; } }
PHP);
    $autoload = static function (string $class) use ($fixture): void {
        if (str_starts_with($class, 'AuditFs\\')) {
            require_once $fixture;
        }
    };
    spl_autoload_register($autoload);
    $builderConstructions = 0;
    $output = new BufferedOutput();
    $create = static function (array $input, string $environment, bool $discovery = true) use ($root, $output, &$builderConstructions) {
        $io = new IOFactory(
            new class ($input) implements InputFactoryInterface {
                public function __construct(private array $input)
                {
                }
                public function createInput(): InputInterface
                {
                    $input = new ArrayInput($this->input);
                    $input->setInteractive(false);
                    return $input;
                }
            },
            new class ($output) implements OutputFactoryInterface {
                public function __construct(private BufferedOutput $output)
                {
                }
                public function createOutput(): OutputInterface
                {
                    return $this->output;
                }
            },
        );
        $definition = new ConfigDefinition([
            new Componenta\ClassFinder\ConfigProvider(),
            new Componenta\App\ConfigProvider(),
            new Componenta\App\Console\ConfigProvider(),
            new Componenta\Error\ConfigProvider(),
            new Componenta\CQRS\ConfigProvider(),
            new Componenta\CQRS\App\ConfigProvider(),
            static function () use ($io, &$builderConstructions): array {
                return [
                    'cqrs.map_file' => 'cqrs.php',
                    DIKey::DEPENDENCIES => [
                        DIKey::SERVICES => [IOFactory::class => $io],
                        DIKey::DELEGATORS => [CqrsBuilder::class => [static function (CqrsBuilder $builder) use (&$builderConstructions): CqrsBuilder {
                            ++$builderConstructions;
                            return $builder;
                        }]],
                    ],
                ];
            },
        ], $discovery ? new DiscoveryDefinition(['src']) : null);
        $result = ConfigFactory::create(new PathResolver($root), $definition, new Environment(['APP_ENV' => $environment]));
        $container = (new ContainerFactory())->create($result->config, $result->dependencies);
        if ($discovery) {
            Assert::assertSame($result->discovered, $container->get('app.discovery.source'));
        }
        return $container;
    };
    try {
        foreach (['development','production'] as $environment) {
            $initial = $builderConstructions;
            foreach ([['command' => 'list'],['command' => 'help','command_name' => 'app:build']] as $input) {
                $status = Runner::run(Scope::CLI, $create($input, $environment));
                Assert::assertSame(0, $status, $output->fetch());
                Assert::assertSame($initial, $builderConstructions);
                Assert::assertFileDoesNotExist($root.'/cqrs.php');
            }
            $container = $create(['command' => 'app:build'], $environment);
            $before = $container->get(CommandBusInterface::class)->dispatch(new AuditFs\Message('source'))->result?->value;
            Assert::assertSame('filesystem:source', $before);
            Assert::assertSame(0, Runner::run(Scope::CLI, $container), $output->fetch());
            Assert::assertSame($initial + 1, $builderConstructions);
            $fresh = $create(['command' => 'list'], $environment, false);
            Assert::assertSame('filesystem:built', $fresh->get(CommandBusInterface::class)->dispatch(new AuditFs\Message('built'))->result?->value);
            Assert::assertSame('filesystem:query', $fresh->get(QueryBusInterface::class)->handle(new AuditFs\Message('query')));

            unlink($root.'/cqrs.php');
        }
    } finally {
        spl_autoload_unregister($autoload);
        if (is_file($root.'/cqrs.php')) {
            unlink($root.'/cqrs.php');
        }
        unlink($fixture);
        rmdir($root.'/src');
        rmdir($root);
    }
});
