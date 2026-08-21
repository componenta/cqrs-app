<?php

declare(strict_types=1);

use Componenta\ClassFinder\Attribute\DevOnly;
use Componenta\CQRS\App\Compile\CqrsMapAutowireEntryContributor;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\App\Map\DiscoveryCqrsMapProvider;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\CQRS\Map\CompositeCqrsMapProvider;
use Componenta\CQRS\Map\CqrsMap;
use Componenta\CQRS\Map\CqrsMapProviderInterface;
use Componenta\CQRS\Map\HandlerDescriptor;
use Componenta\CQRS\Query\Attribute\AsQueryHandler;
use Componenta\Tokenizer\ClassInfo;

final readonly class CqrsContributionCommand {}
final readonly class CqrsContributionQuery {}
final readonly class CqrsConfiguredCommand {}

#[AsCommandHandler]
final readonly class CqrsContributionCommandHandler
{
    public function __invoke(CqrsContributionCommand $command): void {}
}

#[AsQueryHandler]
final readonly class CqrsContributionQueryHandler
{
    public function __invoke(CqrsContributionQuery $query): void {}
}

final readonly class CqrsConfiguredCommandHandler
{
    public function __invoke(CqrsConfiguredCommand $command): void {}
}

it('keeps the discovery index dev-only and exposes its discovered entries', function (): void {
    $index = new CqrsDiscoveryIndex();
    $index->handle(new ClassInfo(CqrsContributionCommandHandler::class));
    $index->handle(new ClassInfo(CqrsContributionQueryHandler::class));
    $index->finalize();

    expect((new ReflectionClass(CqrsDiscoveryIndex::class))->getAttributes(DevOnly::class))
        ->toHaveCount(1)
        ->and(array_map(
            static fn(Componenta\DI\Compile\Autowire\AutowireEntry $entry): string => $entry->class,
            iterator_to_array($index->entries()),
        ))->toBe([
            CqrsContributionCommandHandler::class,
            CqrsContributionQueryHandler::class,
        ]);
});

it('contributes class services from the full effective map for production factory compilation', function (): void {
    $index = new CqrsDiscoveryIndex();
    $index->handle(new ClassInfo(CqrsContributionCommandHandler::class));
    $index->handle(new ClassInfo(CqrsContributionQueryHandler::class));
    $index->finalize();

    $configured = new readonly class implements CqrsMapProviderInterface {
        public function map(): CqrsMap
        {
            return new CqrsMap(commandHandlers: [
                CqrsConfiguredCommand::class => new HandlerDescriptor(
                    CqrsConfiguredCommandHandler::class,
                    '__invoke',
                ),
            ]);
        }
    };
    $effective = new CompositeCqrsMapProvider(
        $configured,
        new DiscoveryCqrsMapProvider($index),
    );
    $classes = array_map(
        static fn(Componenta\DI\Compile\Autowire\AutowireEntry $entry): string => $entry->class,
        iterator_to_array((new CqrsMapAutowireEntryContributor($effective))->entries()),
    );
    $expected = [
        CqrsConfiguredCommandHandler::class,
        CqrsContributionCommandHandler::class,
        CqrsContributionQueryHandler::class,
    ];
    sort($expected);

    expect($classes)->toBe($expected);
});
