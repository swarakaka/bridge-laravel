<?php

declare(strict_types=1);

namespace Bridge\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Symfony\Component\Process\Process;

/**
 * Starts the Node SSR server built by `vite build --ssr` (bridge.ssr.bundle).
 */
final class SsrCommand extends Command
{
    protected $signature = 'bridge:ssr {--bundle= : Path to the SSR bundle relative to the app root}';

    protected $description = 'Start the Bridge SSR server';

    public function handle(Repository $config): int
    {
        $bundle = (string) ($this->option('bundle') ?: $config->get('bridge.ssr.bundle', 'bootstrap/ssr/ssr.js'));
        $path = $this->laravel->basePath($bundle);

        if (! is_file($path)) {
            $this->components->error("SSR bundle not found at [{$bundle}]. Build it with `vite build --ssr resources/js/ssr.ts --outDir bootstrap/ssr`.");

            return self::FAILURE;
        }

        $this->components->info("Starting SSR server from [{$bundle}] on {$config->get('bridge.ssr.url')}.");

        $process = new Process(['node', $path], $this->laravel->basePath(), ['BRIDGE_SSR_URL' => (string) $config->get('bridge.ssr.url')], null, null);
        $process->setTimeout(null);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $process->getExitCode() ?? self::FAILURE;
    }
}
