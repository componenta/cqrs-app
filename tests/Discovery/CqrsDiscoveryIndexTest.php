<?php

declare(strict_types=1);

use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\App\Exception\InvalidDiscoveryDeclarationException;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\CQRS\Command\Attribute\AsCommandListener;
use Componenta\CQRS\Command\Event\CommandFailedEvent;
use Componenta\CQRS\Command\Event\CommandListenerInterface;
use Componenta\CQRS\Command\Event\CommandProcessedEvent;
use Componenta\CQRS\Command\Event\CommandProcessEvent;
use Componenta\CQRS\Map\CqrsMap;
use Componenta\CQRS\Query\Attribute\AsQueryHandler;
use Componenta\Tokenizer\ClassInfo;

final readonly class DiscoveryCommandA
{
}

final readonly class DiscoveryCommandB
{
}

final readonly class DiscoveryCommandC
{
}

final readonly class DiscoveryQueryA
{
}

final readonly class DiscoveryQueryB
{
}

#[Attribute(Attribute::TARGET_CLASS)]
final class DiscoveryMetadata
{
    public static int $instances = 0;

    public function __construct(
        public string $value,
        public int $count = 1,
    ) {
        ++self::$instances;
    }
}

#[DiscoveryMetadata('compiled', count: 7)]
final readonly class DiscoveryMetadataCommand
{
}

#[AsCommandHandler]
final readonly class DiscoveryClassCommandHandler
{
    public function __invoke(DiscoveryCommandC $command): void
    {
    }
}

final readonly class DiscoveryMethodHandlers
{
    #[AsCommandHandler]
    public function first(DiscoveryCommandA $command): void
    {
    }

    #[AsCommandHandler(command: DiscoveryCommandB::class)]
    public function second(DiscoveryCommandA|DiscoveryCommandB $command): void
    {
    }
}

final readonly class DiscoveryQueryHandlers
{
    #[AsQueryHandler]
    public function first(DiscoveryQueryA $query): void
    {
    }

    #[AsQueryHandler(query: DiscoveryQueryB::class)]
    public function second(DiscoveryQueryA|DiscoveryQueryB $query): void
    {
    }
}

#[AsCommandListener(
    DiscoveryCommandA::class,
    priority: -100,
)]
#[AsCommandListener(
    DiscoveryCommandA::class,
    priority: 100,
    eventTypes: [CommandProcessedEvent::class],
)]
#[AsCommandListener(
    DiscoveryCommandA::class,
    priority: 100,
    eventTypes: [CommandProcessedEvent::class],
)]
final readonly class DiscoveryListener implements CommandListenerInterface
{
    public function handleEvent(
        CommandProcessEvent|CommandProcessedEvent|CommandFailedEvent $event,
    ): void {
    }
}

final readonly class DiscoveryPrivateHandler
{
    #[AsCommandHandler(command: DiscoveryCommandA::class)]
    private function handle(): void
    {
    }
}

final readonly class DiscoveryStaticHandler
{
    #[AsQueryHandler(query: DiscoveryQueryA::class)]
    public static function handle(): void
    {
    }
}

final readonly class DiscoveryUnionWithoutExplicitHandler
{
    #[AsCommandHandler]
    public function handle(DiscoveryCommandA|DiscoveryCommandB $command): void
    {
    }
}

#[AsCommandHandler]
final readonly class DiscoveryMixedHandler
{
    #[AsCommandHandler]
    public function __invoke(DiscoveryCommandA $command): void
    {
    }
}

final readonly class DiscoveryDuplicateHandlerA
{
    #[AsCommandHandler(command: DiscoveryCommandA::class)]
    public function handle(DiscoveryCommandA $command): void
    {
    }
}

final readonly class DiscoveryDuplicateHandlerB
{
    #[AsCommandHandler(command: DiscoveryCommandA::class)]
    public function handle(DiscoveryCommandA $command): void
    {
    }
}

final readonly class DiscoveryZeroParameterHandler
{
    #[AsCommandHandler(command: DiscoveryCommandA::class)]
    public function handle(): void
    {
    }
}

final readonly class DiscoveryRequiredSecondParameterHandler
{
    #[AsCommandHandler(command: DiscoveryCommandA::class)]
    public function handle(DiscoveryCommandA $command, string $required): void
    {
    }
}

final readonly class DiscoveryAdditionalVariadicHandler
{
    #[AsCommandHandler(command: DiscoveryCommandA::class)]
    public function handle(DiscoveryCommandA $command, string ...$extra): void
    {
    }
}

final class DiscoveryAdditionalByReferenceHandler
{
    #[AsQueryHandler(query: DiscoveryQueryA::class)]
    public function handle(DiscoveryQueryA $query, ?string &$optional = null): void
    {
    }
}

final readonly class DiscoveryScalarMessageHandler
{
    #[AsCommandHandler(command: 'logical.command')]
    public function handle(string $command): void
    {
    }
}

final class DiscoveryMagicMethodHandler
{
    #[AsCommandHandler(command: 'logical.command')]
    public function __get(string $name): mixed
    {
        return null;
    }
}

final readonly class DiscoveryByReferenceHandler
{
    #[AsCommandHandler(command: DiscoveryCommandA::class)]
    public function handle(DiscoveryCommandA &$command): void
    {
    }
}

final readonly class DiscoveryMismatchedMessageHandler
{
    #[AsCommandHandler(command: DiscoveryCommandA::class)]
    public function handle(DiscoveryCommandB $command): void
    {
    }
}

final readonly class DiscoveryUnknownAttributeArgumentHandler
{
    #[AsCommandHandler(unsupported: DiscoveryCommandA::class)]
    public function handle(DiscoveryCommandA $command): void
    {
    }
}

abstract class DiscoveryInheritedHandlerBase
{
    #[AsCommandHandler(command: DiscoveryCommandA::class)]
    public function handle(DiscoveryCommandA $command): void
    {
    }
}

final class DiscoveryInheritedHandlerChild extends DiscoveryInheritedHandlerBase
{
}

#[Attribute(Attribute::TARGET_METHOD)]
final class DiscoveryMethodOnlyMetadata
{
}

/**
 * @param list<class-string> $classes
 * @param list<class-string> $metadata
 */
function finalizedDiscoveryIndex(array $classes, array $metadata = []): CqrsDiscoveryIndex
{
    $index = new CqrsDiscoveryIndex($metadata);

    foreach ($classes as $class) {
        $index->handle(new ClassInfo($class));
    }

    $index->finalize();

    return $index;
}

it('discovers command, query, listener, and generic metadata descriptors in one pass', function (): void {
    DiscoveryMetadata::$instances = 0;
    $index = finalizedDiscoveryIndex([
        DiscoveryClassCommandHandler::class,
        DiscoveryMethodHandlers::class,
        DiscoveryQueryHandlers::class,
        DiscoveryListener::class,
        DiscoveryMetadataCommand::class,
    ], [DiscoveryMetadata::class]);
    $artifact = $index->map()->toArray();

    expect($artifact['commands']['handlers'][DiscoveryCommandA::class])->toBe([
        'service' => DiscoveryMethodHandlers::class,
        'method' => 'first',
    ])->and($artifact['commands']['handlers'][DiscoveryCommandB::class])->toBe([
        'service' => DiscoveryMethodHandlers::class,
        'method' => 'second',
    ])->and($artifact['commands']['handlers'][DiscoveryCommandC::class])->toBe([
        'service' => DiscoveryClassCommandHandler::class,
        'method' => '__invoke',
    ])->and($artifact['queries']['handlers'][DiscoveryQueryA::class])->toBe([
        'service' => DiscoveryQueryHandlers::class,
        'method' => 'first',
    ])->and($artifact['queries']['handlers'][DiscoveryQueryB::class])->toBe([
        'service' => DiscoveryQueryHandlers::class,
        'method' => 'second',
    ])->and(array_column(
        $artifact['commands']['listeners'][DiscoveryCommandA::class],
        'priority',
    ))->toBe([100, -100])
        ->and($artifact['commands']['metadata'][DiscoveryMetadataCommand::class][DiscoveryMetadata::class])
        ->toBe(['arguments' => [0 => 'compiled', 'count' => 7]])
        ->and($artifact['commands']['known'])->toHaveKeys([
            DiscoveryCommandA::class,
            DiscoveryCommandB::class,
            DiscoveryCommandC::class,
            DiscoveryMetadataCommand::class,
        ])
        ->and(DiscoveryMetadata::$instances)->toBe(0);
});

it('produces byte-identical maps regardless of discovery order', function (): void {
    $classes = [
        DiscoveryMetadataCommand::class,
        DiscoveryListener::class,
        DiscoveryQueryHandlers::class,
        DiscoveryMethodHandlers::class,
        DiscoveryClassCommandHandler::class,
    ];

    $forward = finalizedDiscoveryIndex($classes, [DiscoveryMetadata::class]);
    $reverse = finalizedDiscoveryIndex(array_reverse($classes), [DiscoveryMetadata::class]);

    expect(serialize($forward->map()->toArray()))
        ->toBe(serialize($reverse->map()->toArray()));
});

it('rejects use before finalization and mutation after finalization', function (): void {
    $index = new CqrsDiscoveryIndex();

    expect(fn(): CqrsMap => $index->map())
        ->toThrow(InvalidDiscoveryDeclarationException::class, 'not finalized');

    $index->finalize();

    expect(fn() => $index->finalize())
        ->toThrow(LogicException::class, 'already finalized')
        ->and(fn() => $index->handle(new ClassInfo(DiscoveryCommandA::class)))
        ->toThrow(LogicException::class, 'already finalized');
});

it('rejects invalid handler method declarations and ambiguous inference', function (
    string $class,
    string $message,
): void {
    $index = new CqrsDiscoveryIndex();

    expect(fn() => $index->handle(new ClassInfo($class)))
        ->toThrow(InvalidDiscoveryDeclarationException::class, $message);
})->with([
    'private command method' => [
        DiscoveryPrivateHandler::class,
        'public non-static operation',
    ],
    'static query method' => [
        DiscoveryStaticHandler::class,
        'public non-static operation',
    ],
    'union without explicit message' => [
        DiscoveryUnionWithoutExplicitHandler::class,
        'use an explicit message name',
    ],
    'class and method attribute conflict' => [
        DiscoveryMixedHandler::class,
        'both the class and method',
    ],
    'explicit handler without message slot' => [
        DiscoveryZeroParameterHandler::class,
        'first parameter slot',
    ],
    'required second parameter' => [
        DiscoveryRequiredSecondParameterHandler::class,
        'cannot require additional parameter',
    ],
    'additional variadic parameter' => [
        DiscoveryAdditionalVariadicHandler::class,
        'additional variadic or by-reference parameter',
    ],
    'additional by-reference parameter' => [
        DiscoveryAdditionalByReferenceHandler::class,
        'additional variadic or by-reference parameter',
    ],
    'scalar message parameter with explicit alias' => [
        DiscoveryScalarMessageHandler::class,
        'must accept an object message',
    ],
    'unsupported magic handler method' => [
        DiscoveryMagicMethodHandler::class,
        'public non-static operation',
    ],
    'message passed by reference' => [
        DiscoveryByReferenceHandler::class,
        'normal by-value parameter',
    ],
    'explicit message incompatible with parameter' => [
        DiscoveryMismatchedMessageHandler::class,
        'is incompatible with the first parameter',
    ],
    'unsupported handler attribute argument' => [
        DiscoveryUnknownAttributeArgumentHandler::class,
        'unsupported argument',
    ],
]);

it('does not rediscover inherited method attributes on child services', function (): void {
    $index = finalizedDiscoveryIndex([
        DiscoveryInheritedHandlerBase::class,
        DiscoveryInheritedHandlerChild::class,
    ]);

    expect($index->map()->commandHandler(DiscoveryCommandA::class))->toBeNull();
});

it('rejects metadata attributes that cannot target command classes', function (): void {
    $index = new CqrsDiscoveryIndex([DiscoveryMethodOnlyMetadata::class]);

    expect(fn() => $index->handle(new ClassInfo(DiscoveryCommandA::class)))
        ->toThrow(InvalidDiscoveryDeclarationException::class, 'must allow class targets');
});

it('rejects different handlers for the same message', function (): void {
    $index = new CqrsDiscoveryIndex();
    $index->handle(new ClassInfo(DiscoveryDuplicateHandlerA::class));

    expect(fn() => $index->handle(new ClassInfo(DiscoveryDuplicateHandlerB::class)))
        ->toThrow(
            InvalidDiscoveryDeclarationException::class,
            'Multiple CQRS command handlers',
        );
});
