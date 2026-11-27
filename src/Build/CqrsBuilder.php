<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Build;

use Componenta\App\Build\ApplicationBuilderInterface;
use Componenta\App\Build\BuildContext;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\Internal\RegistrationNormalizer;

final readonly class CqrsBuilder implements ApplicationBuilderInterface
{
    public const string ARTIFACT_ID = 'cqrs.maps';
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<array<string, mixed>> $commandHandlers
     * @param list<array<string, mixed>> $queryHandlers
     * @param list<array<string, mixed>> $commandListeners
     */
    public function __construct(
        private CqrsDiscoveryIndex $discovery,
        private array $commandHandlers = [],
        private array $queryHandlers = [],
        private array $commandListeners = [],
    ) {
    }

    public function build(BuildContext $build): void
    {
        $explicit = RegistrationNormalizer::registrations($this->commandHandlers, $this->queryHandlers, $this->commandListeners);
        $maps = RegistrationNormalizer::merge([
            'command_handlers' => $this->discovery->commandHandlers(),
            'query_handlers' => $this->discovery->queryHandlers(),
            'command_listeners' => $this->discovery->commandListeners(),
        ], $explicit);
        $build->write(self::ARTIFACT_ID, $maps, self::SCHEMA_VERSION);
    }
}
