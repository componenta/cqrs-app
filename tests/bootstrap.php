<?php

declare(strict_types=1);

$localAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($localAutoload)) {
    $loader = require $localAutoload;
} else {
    require dirname(__DIR__, 2) . '/cqrs/tests/bootstrap.php';
    $loader->setPsr4('Componenta\\ClassFinder\\', dirname(__DIR__, 2) . '/class-finder/src');
    $loader->setPsr4('Componenta\\Error\\', dirname(__DIR__, 2) . '/error-handler/src');
    $loader->setPsr4('Componenta\\App\\', [
        dirname(__DIR__, 2) . '/app/src',
        dirname(__DIR__, 2) . '/app-console/src',
    ]);
}
$loader->setPsr4('Componenta\\CQRS\\App\\', dirname(__DIR__) . '/src');
$loader->setPsr4('Componenta\\CQRS\\App\\Tests\\', __DIR__);
