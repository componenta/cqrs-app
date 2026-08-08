<?php

declare(strict_types=1);

use Componenta\CQRS\App\Compile\CommandAttributeMapContributor;
use Componenta\CQRS\Command\Attribute\Async;
use Componenta\CQRS\ConfigKey;

#[Async(transport: 'events')]
final readonly class CqrsAppContributorAnnotatedCommand {}

describe('CommandAttributeMapContributor', function () {
    it('returns the command attribute map config delta', function () {
        $delta = (new CommandAttributeMapContributor())->compile([
            CqrsAppContributorAnnotatedCommand::class,
        ]);

        expect($delta)->toHaveKey(ConfigKey::COMMAND_ATTRIBUTE_MAP)
            ->and($delta[ConfigKey::COMMAND_ATTRIBUTE_MAP]['attributes'])
            ->toHaveKey(CqrsAppContributorAnnotatedCommand::class);
    });

    it('omits the command attribute section when no metadata is discovered', function () {
        expect((new CommandAttributeMapContributor())->compile([]))->toBe([]);
    });
});
