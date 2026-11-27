<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Tests;

use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\App\Build\BuildStore;
use Componenta\App\Build\PreparedBuild;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\App\Console\Command\BuildCommand;
use Componenta\Config\ConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\CQRS\App\Build\CqrsBuilder;

final class BuildFixture
{
    public static function store(string $root): BuildStore
    {
        return new BuildStore($root . '/build');
    }

    public static function read(string $root): array
    {
        return self::store($root)->load()?->read(CqrsBuilder::ARTIFACT_ID, CqrsBuilder::SCHEMA_VERSION)
            ?? throw new \LogicException('The CQRS fixture has no prepared map.');
    }

    public static function publish(string $root, array $maps, int $schema = CqrsBuilder::SCHEMA_VERSION): PreparedBuild
    {
        $store = self::store($root);
        $build = $store->begin();
        $build->write(CqrsBuilder::ARTIFACT_ID, $maps, $schema);
        return $store->publish($build);
    }

    public static function build(string $root, CqrsBuilder $builder): PreparedBuild
    {
        return (new ApplicationBuildOrchestrator([$builder], self::store($root)))->build();
    }

    public static function provider(string $root, bool $forceSource = false): \Closure
    {
        return static function () use ($root, $forceSource): array {
            $store = self::store($root);
            $prepared = $forceSource ? null : $store->load();
            return [ConfigKey::DEPENDENCIES => [
                ConfigKey::FACTORIES => [
                    BuildStore::class => static fn (): BuildStore => $store,
                    BuildCommand::class => static fn (ContainerValue $container): BuildCommand => new BuildCommand(
                        static fn (): PreparedBuild => $container->get(ApplicationBuildOrchestrator::class)->build(),
                    ),
                ],
                ConfigKey::SERVICES => $prepared === null ? [] : [AppConfigKey::PREPARED_BUILD => $prepared],
            ]];
        };
    }

    public static function remove(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        if (!str_starts_with($root, sys_get_temp_dir() . '/cqrs_')) {
            throw new \LogicException('Unexpected CQRS fixture directory.');
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($root);
    }
}
