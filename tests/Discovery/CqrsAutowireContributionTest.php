<?php

declare(strict_types=1);

use Componenta\ClassFinder\Attribute\DevOnly;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\Command\Attribute\AsCommandHandler;
use Componenta\CQRS\Query\Attribute\AsQueryHandler;
use Componenta\Tokenizer\ClassInfo;

final readonly class CqrsContributionCommand {}
final readonly class CqrsContributionQuery {}

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

it('contributes discovered handler services without replaying discovery in production', function (): void {
    $index = new CqrsDiscoveryIndex();
    $index->handle(new ClassInfo(CqrsContributionCommandHandler::class));
    $index->handle(new ClassInfo(CqrsContributionQueryHandler::class));
    $index->finalize();

    expect((new ReflectionClass(CqrsDiscoveryIndex::class))->getAttributes(DevOnly::class))
        ->toHaveCount(1)
        ->and(array_map(
            static fn (Componenta\DI\Compile\Autowire\AutowireEntry $entry): string => $entry->class,
            iterator_to_array($index->entries()),
        ))->toBe([
            CqrsContributionCommandHandler::class,
            CqrsContributionQueryHandler::class,
        ]);
});
