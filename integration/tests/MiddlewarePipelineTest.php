<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Tests\Integration;

use Generator;
use LogicException;
use RuntimeException;
use PHPUnit\Framework\Assert;
use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\ClassFinder\ClassIterator;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey as DIKey;
use Componenta\Config\Environment;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\CQRS\Command\Attribute\AsCommandListener;
use Componenta\CQRS\Command\CommandBusInterface;
use Componenta\CQRS\Command\Event\CommandListenerInterface;
use Componenta\CQRS\Command\Event\CommandProcessEvent;
use Componenta\CQRS\Command\Event\CommandProcessedEvent;
use Componenta\CQRS\Command\Event\CommandFailedEvent;
use Componenta\CQRS\Command\Exception\RetryableExceptionInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\Middleware\PolicyMiddleware;
use Componenta\CQRS\Command\Middleware\RetryMiddleware;
use Componenta\CQRS\Command\Middleware\ResourceLockMiddleware;
use Componenta\CQRS\Command\Middleware\TransactionMiddleware;
use Componenta\CQRS\Command\Middleware\EventMiddleware;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\CommandWorker;
use Componenta\CQRS\Command\Transport\Envelope;
use Componenta\CQRS\Command\Transport\ExecutionMode;
use Componenta\CQRS\Command\Transport\TransportInterface;
use Componenta\CQRS\Command\Transport\TransportRegistry;
use Componenta\CQRS\Command\Transport\TransportRegistryInterface;
use Componenta\CQRS\Command\Transport\OperationContextSerializerInterface;
use Componenta\CQRS\Command\Transport\JsonOperationContextSerializer;
use Componenta\CQRS\Policy\Transport\ActorRepositoryInterface;
use Componenta\CQRS\Query\Attribute\AsQueryHandler;
use Componenta\CQRS\Query\QueryBusInterface;
use Componenta\DI\ContainerFactory;
use Componenta\Identity\UuidInterface;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\Guest;
use Componenta\Policy\Policies\Allow;
use Componenta\CQRS\Retry\Attribute\Retry;
use Componenta\CQRS\Lock\Attribute\Lock;
use Componenta\CQRS\Transport\Attribute\Async;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;
use Componenta\Tokenizer\ClassInfo;
use Cycle\Database\Database;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\Driver\SQLite\SQLiteDriver;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

function check(mixed $actual, mixed $expected, string $context): void
{
    Assert::assertSame($expected, $actual, $context);
}
#[Async('audit'), Retry(attempts: 2, delayMs: 0), Lock('audit:{id}', blocking: false), Allow]
final readonly class IntegratedCommand implements ActorAwareInterface
{
    public function __construct(public object $actor, public string $id, public bool $alwaysFail = false)
    {
    }
}
#[Allow]
final readonly class IntegratedQuery
{
}
final class TransientFailure extends RuntimeException implements RetryableExceptionInterface
{
}
final class IntegrationState
{
    public array $attempts = [];
    public array $events = [];
    public array $originalIds = [];
    public array $traceIds = [];
}
#[AsCommandHandler]
final readonly class IntegratedHandler
{
    public function __construct(private DatabaseInterface $database, private IntegrationState $state, private LockFactory $locks)
    {
    }
    public function __invoke(IntegratedCommand $command): string
    {
        $probe = $this->locks->createLock('audit:' . $command->id);
        check($probe->acquire(false), false, 'lock held during handler');
        $attempt = $this->state->attempts[$command->id] = ($this->state->attempts[$command->id] ?? 0) + 1;
        $this->database->execute('INSERT INTO audit_results (id, attempt) VALUES (?, ?)', [$command->id, $attempt]);
        if ($command->alwaysFail || $attempt === 1) {
            throw new TransientFailure('first attempt rolls back');
        }
        return $command->id;
    }
}
#[AsQueryHandler]
final readonly class IntegratedQueryHandler
{
    public function __construct(private DatabaseInterface $database)
    {
    }
    public function __invoke(IntegratedQuery $query): array
    {
        return $this->database->query('SELECT id, attempt FROM audit_results ORDER BY id')->fetchAll();
    }
}
#[AsCommandListener(IntegratedCommand::class)]
final readonly class IntegratedListener implements CommandListenerInterface
{
    public function __construct(private IntegrationState $state)
    {
    }
    public function handleEvent(CommandProcessEvent|CommandProcessedEvent|CommandFailedEvent $event): void
    {
        $this->state->events[] = $event::class;
        $this->state->originalIds[] = $event->operation->attributes[CommandWorker::ATTR_ORIGINAL_OPERATION_ID] ?? null;
        $this->state->traceIds[] = $event->operation->attributes['trace'] ?? null;
    }
}
final class Queue implements TransportInterface
{
    public array $items = [];
    public int $acks = 0;
    public int $rejects = 0;
    public function send(Envelope $envelope, int $delay = 0): Envelope
    {
        $this->items[] = $envelope;
        return $envelope;
    }
    public function get(): ?Envelope
    {
        return array_shift($this->items);
    }
    public function ack(Envelope $envelope): void
    {
        ++$this->acks;
    }
    public function reject(Envelope $envelope): void
    {
        ++$this->rejects;
    }
}
it('preserves transport, policy, retry, locking and transaction semantics with source and built maps', function (): void {
    $root = sys_get_temp_dir() . '/cqrs_all_' . bin2hex(random_bytes(8));
    try {
        foreach (['source', 'built'] as $mode) {
            $database = new Database('audit', '', SQLiteDriver::create(new SQLiteDriverConfig()));
            $database->execute('CREATE TABLE audit_results (id TEXT NOT NULL, attempt INTEGER NOT NULL)');
            $queue = new Queue();
            $transports = new TransportRegistry();
            $transports->register('audit', $queue);
            $state = new IntegrationState();
            $locks = new LockFactory(new InMemoryStore());
            $visits = 0;
            $classes = new ClassIterator((static function () use (&$visits, $mode): Generator {
                ++$visits;
                if ($mode === 'built') {
                    throw new RuntimeException('Built integration scanned source.');
                }
                foreach ([IntegratedHandler::class, IntegratedQueryHandler::class, IntegratedListener::class] as $class) {
                    yield $class => new ClassInfo($class);
                }
            })());
            $actors = new class () implements ActorRepositoryInterface {
                public function findByUuid(UuidInterface $uuid): ?object
                {
                    throw new LogicException('Guest needs no repository.');
                }
            };
            $composition = (new ConfigFactory())->create(
                new Environment(['APP_ENV' => 'production']),
                new \Componenta\App\ConfigProvider(),
                new \Componenta\CQRS\ConfigProvider(),
                new \Componenta\CQRS\App\ConfigProvider(),
                new \Componenta\Policy\ConfigProvider(),
                new \Componenta\CQRS\Policy\ConfigProvider(),
                new \Componenta\CQRS\Retry\ConfigProvider(),
                new \Componenta\CQRS\Lock\ConfigProvider(),
                new \Componenta\CQRS\Transport\ConfigProvider(),
                new \Componenta\CQRS\Policy\Transport\ConfigProvider(),
                static fn (): array => [
                    'cqrs.map_file' => 'cqrs.php',
                    \Componenta\CQRS\ConfigKey::COMMAND_MIDDLEWARES => [
                        PolicyMiddleware::class, TransportMiddleware::class, RetryMiddleware::class,
                        ResourceLockMiddleware::class, TransactionMiddleware::class, EventMiddleware::class,
                    ],
                    \Componenta\CQRS\ConfigKey::QUERY_MIDDLEWARES => [\Componenta\CQRS\Query\Middleware\PolicyMiddleware::class],
                    DIKey::DEPENDENCIES => [DIKey::DELEGATORS => [OperationContextSerializerInterface::class => [static fn (OperationContextSerializerInterface $inner) => new JsonOperationContextSerializer(['trace'])]], DIKey::SERVICES => [
                        PathResolverInterface::class => new PathResolver($root),
                        \Componenta\App\ConfigKey::DISCOVERY_SOURCE => $classes,
                        ClassIteratorInterface::class => $classes,
                        DatabaseInterface::class => $database,
                        LockFactory::class => $locks,
                        IntegrationState::class => $state,
                        TransportRegistryInterface::class => $transports,
                        ActorRepositoryInterface::class => $actors,
                    ]],
                ],
            );
            $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
            $bus = $container->get(CommandBusInterface::class);
            $worker = new CommandWorker(
                $bus,
                $container->get(CommandSerializerInterface::class),
                $container->get(OperationContextSerializerInterface::class),
                $queue,
                'audit',
            );
            $operation = $bus->dispatch(new IntegratedCommand(new Guest(), 'success'), ['trace' => 'audit-trace']);
            check($operation->attributes[TransportMiddleware::ATTR_EXECUTION_MODE], ExecutionMode::ASYNC, 'producer mode');
            check($state->attempts, [], 'producer does not handle command');
            check($worker->processOne(), true, 'successful delivery');
            check($queue->acks, 1, 'ack');
            check($queue->rejects, 0, 'no rejection on retry success');
            check($state->attempts, ['success' => 2], 'retry executes twice');
            check($container->get(QueryBusInterface::class)->handle(new IntegratedQuery()), [['id' => 'success', 'attempt' => 2]], 'rollback and committed result');
            check($state->events, [CommandProcessEvent::class, CommandFailedEvent::class, CommandProcessEvent::class, CommandProcessedEvent::class], 'events for both attempts');
            check($state->originalIds, array_fill(0, 4, $operation->id->toString()), 'original operation id across retry');
            check($state->traceIds, array_fill(0, 4, 'audit-trace'), 'transported trace');
            $bus->dispatch(new IntegratedCommand(new Guest(), 'failure', true));
            check($worker->processOne(), true, 'failed delivery consumed');
            check($queue->acks, 1, 'failed command not acknowledged');
            check($queue->rejects, 1, 'failed command rejected');
            check($state->attempts, ['success' => 2, 'failure' => 2], 'retry exhausted');
            check($container->get(QueryBusInterface::class)->handle(new IntegratedQuery()), [['id' => 'success', 'attempt' => 2]], 'all failed attempts rolled back');
            foreach (['success', 'failure'] as $id) {
                $probe = $locks->createLock('audit:' . $id);
                check($probe->acquire(false), true, 'lock released after ' . $id);
                $probe->release();
            }
            check($worker->processOne(), false, 'empty queue');
            check($visits, $mode === 'source' ? 1 : 0, 'source visit count');
            if ($mode === 'source') {
                $container->get(ApplicationBuildOrchestrator::class)->build();
                check($visits, 1, 'builder shares source');
            }
        }
    } finally {
        if (is_file($root . '/cqrs.php')) {
            unlink($root . '/cqrs.php');
        }
        if (is_dir($root)) {
            rmdir($root);
        }
    }

});
