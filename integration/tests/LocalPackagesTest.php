<?php

declare(strict_types=1);

use Composer\InstalledVersions;

it('loads every Componenta integration dependency from its working package directory', function (): void {
    $manifest = json_decode(file_get_contents(dirname(__DIR__) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $packagesDirectory = dirname(__DIR__, 3);

    foreach (array_keys($manifest['require']) as $package) {
        if (!str_starts_with($package, 'componenta/')) {
            continue;
        }

        $expected = $packagesDirectory . '/' . substr($package, strlen('componenta/'));
        expect($expected)->toBeDirectory();
        expect(realpath(InstalledVersions::getInstallPath($package)))->toBe(realpath($expected));
    }
});
