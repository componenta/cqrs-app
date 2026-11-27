<?php

declare(strict_types=1);

use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\ClassFinder\ClassIterator;
use Componenta\CQRS\App\Build\CqrsBuilder;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\App\Tests\BuildFixture;

it('unpublishes centrally while retaining the selected runtime map and source files', function (): void {
    $root = sys_get_temp_dir() . '/cqrs_cleanup_' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    file_put_contents($root . '/source.php', 'preserved');
    $store = BuildFixture::store($root);
    $orchestrator = new ApplicationBuildOrchestrator([
        new CqrsBuilder(new CqrsDiscoveryIndex(new ClassIterator([]))),
    ], $store);
    try {
        $orchestrator->clean();
        expect(is_dir($root . '/build'))->toBeFalse();
        $prepared = $orchestrator->build();
        $orchestrator->clean();
        $orchestrator->clean();
        expect($store->load())->toBeNull()
            ->and($prepared->read(CqrsBuilder::ARTIFACT_ID))->toBe([
                'command_handlers' => [], 'query_handlers' => [], 'command_listeners' => [],
            ])->and(file_get_contents($root . '/source.php'))->toBe('preserved');
        expect($orchestrator->build()->generation)->not->toBe($prepared->generation);
    } finally {
        BuildFixture::remove($root);
    }
});
