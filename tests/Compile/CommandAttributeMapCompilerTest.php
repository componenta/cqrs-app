<?php

declare(strict_types=1);

use Componenta\CQRS\App\Compile\CommandAttributeMapCompiler;
use Componenta\CQRS\Command\Attribute\Async;
use Componenta\CQRS\Command\Attribute\Lock;
use Componenta\CQRS\Command\Attribute\Retry;

#[Async(transport: 'emails', delay: 7)]
#[Retry(attempts: 4, delayMs: 50, multiplier: 2.0, maxDelayMs: 500)]
#[Lock('post:{id}', ttl: 12.5, blocking: false)]
final readonly class CqrsAppCompilerAnnotatedCommand
{
    public function __construct(public int $id = 1) {}
}

final readonly class CqrsAppCompilerPlainCommand {}

describe('CommandAttributeMapCompiler', function () {
    it('compiles command attribute descriptors and keeps known commands', function () {
        $map = (new CommandAttributeMapCompiler())->compile(
            [CqrsAppCompilerAnnotatedCommand::class, CqrsAppCompilerPlainCommand::class],
            [CqrsAppCompilerPlainCommand::class],
        );

        expect($map['known'])->toHaveKey(CqrsAppCompilerPlainCommand::class)
            ->and($map['attributes'][CqrsAppCompilerAnnotatedCommand::class]['async'])->toBe([
                'transport' => 'emails',
                'delay' => 7,
            ])
            ->and($map['attributes'][CqrsAppCompilerAnnotatedCommand::class]['retry'])->toBe([
                'attempts' => 4,
                'delayMs' => 50,
                'multiplier' => 2.0,
                'maxDelayMs' => 500,
            ])
            ->and($map['attributes'][CqrsAppCompilerAnnotatedCommand::class]['lock'])->toBe([
                'key' => 'post:{id}',
                'ttl' => 12.5,
                'blocking' => false,
            ]);
    });
});
