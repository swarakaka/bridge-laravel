<?php

declare(strict_types=1);

namespace Bridge\Console;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'bridge:install {--force : Overwrite published files}';

    protected $description = 'Publish the Bridge config and HTML shell view';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'bridge-config', '--force' => (bool) $this->option('force')]);
        $this->call('vendor:publish', ['--tag' => 'bridge-views', '--force' => (bool) $this->option('force')]);

        $this->components->info('Bridge installed.');
        $this->components->bulletList([
            'config/bridge.php published; set shell.view to "app" to use the published resources/views/app.blade.php.',
            'The `bridge` middleware is added to the `web` group automatically (bridge.middleware.auto_register); run bridge:middleware for your own subclass.',
            'Return Bridge::render(\'Component\', [...]) from controllers; content negotiation picks HTML, page or JSON.',
            'For bearer-token clients on web routes, swap VerifyCsrfToken for Bridge\Http\Middleware\VerifyCsrfToken.',
        ]);

        return self::SUCCESS;
    }
}
