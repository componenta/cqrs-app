<?php

declare(strict_types=1);

namespace Componenta\CQRS\App;

final class ConfigKey
{
    /** Explicitly enables or disables runtime CQRS discovery. */
    public const string DISCOVERY_ENABLED = 'Componenta\CQRS\App::DiscoveryEnabled';
}
