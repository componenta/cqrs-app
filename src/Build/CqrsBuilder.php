<?php

declare(strict_types=1);

namespace Componenta\CQRS\App\Build;

use Componenta\App\Build\ApplicationBuilderInterface;
use Componenta\CQRS\App\Discovery\CqrsDiscoveryIndex;
use Componenta\CQRS\Internal\RegistrationNormalizer;
use Componenta\VarExport\VarExport;
use ErrorException;
use RuntimeException;

final readonly class CqrsBuilder implements ApplicationBuilderInterface
{
    /**
     * @param list<array<string, mixed>> $commandHandlers
     * @param list<array<string, mixed>> $queryHandlers
     * @param list<array<string, mixed>> $commandListeners
     */
    public function __construct(
        private CqrsDiscoveryIndex $discovery,
        private string $file,
        private array $commandHandlers = [],
        private array $queryHandlers = [],
        private array $commandListeners = [],
    ) {
    }

    public function build(): void
    {
        $explicit = RegistrationNormalizer::registrations($this->commandHandlers, $this->queryHandlers, $this->commandListeners);
        $maps = RegistrationNormalizer::merge([
            'command_handlers' => $this->discovery->commandHandlers(),
            'query_handlers' => $this->discovery->queryHandlers(),
            'command_listeners' => $this->discovery->commandListeners(),
        ], $explicit);
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . VarExport::withDefaults()->export($maps) . ";\n";
        $directory = dirname($this->file);
        $temporary = null;
        $stream = null;
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
                throw new RuntimeException('Cannot create CQRS map directory "' . $directory . '".');
            }
            $temporary = $directory . '/.' . basename($this->file) . '.' . bin2hex(random_bytes(12)) . '.tmp';
            $stream = fopen($temporary, 'xb');
            if ($stream === false) {
                throw new RuntimeException('Cannot open temporary CQRS map "' . $temporary . '".');
            }
            if (fwrite($stream, $content) !== strlen($content) || !fflush($stream)) {
                throw new RuntimeException('Cannot write complete CQRS map "' . $temporary . '".');
            }
            fclose($stream);
            $stream = null;
            if (!rename($temporary, $this->file)) {
                throw new RuntimeException('Cannot publish CQRS map "' . $this->file . '".');
            }
            $temporary = null;
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($this->file, true);
            }
        } finally {
            try {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if ($temporary !== null && is_file($temporary)) {
                    unlink($temporary);
                }
            } finally {
                restore_error_handler();
            }
        }
    }
}
