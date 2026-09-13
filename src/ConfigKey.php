<?php

declare(strict_types=1);

namespace Componenta\CQRS\App;

final class ConfigKey
{
    public const string MAP_FILE = 'cqrs.map_file';
    public const string DEFAULT_MAP_FILE = 'var/cache/build/cqrs.php';
}
