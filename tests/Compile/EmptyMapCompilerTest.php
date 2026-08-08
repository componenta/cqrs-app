<?php

declare(strict_types=1);

use Componenta\CQRS\App\Command\Locator\AttributeCommandHandlerLocator;
use Componenta\CQRS\App\Command\Locator\AttributeCommandListenersLocator;
use Componenta\CQRS\App\Compile\CommandHandlerMapCompiler;
use Componenta\CQRS\App\Compile\CommandListenersMapCompiler;
use Componenta\CQRS\App\Compile\QueryHandlerMapCompiler;
use Componenta\CQRS\App\Query\Locator\AttributeQueryHandlerLocator;
use Psr\Container\ContainerInterface;

it('returns no config delta for empty finalized CQRS locators', function (): void {
    $container = new class implements ContainerInterface {
        public function get(string $id): mixed
        {
            throw new RuntimeException($id);
        }

        public function has(string $id): bool
        {
            return false;
        }
    };

    $cases = [
        [new CommandHandlerMapCompiler(), new AttributeCommandHandlerLocator($container)],
        [new CommandListenersMapCompiler(), new AttributeCommandListenersLocator($container)],
        [new QueryHandlerMapCompiler(), new AttributeQueryHandlerLocator($container)],
    ];

    foreach ($cases as [$compiler, $listener]) {
        $listener->finalize();
        $result = $compiler->compile($listener, '');

        expect($result->configKey)->toBeNull()
            ->and($result->configValue)->toBeNull()
            ->and($result->files)->toBe([]);
    }
});
