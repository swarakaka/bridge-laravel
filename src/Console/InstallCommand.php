<?php

declare(strict_types=1);

namespace Bridge\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Throwable;

final class InstallCommand extends Command
{
    protected $signature = 'bridge:install {--force : Overwrite published files} {--without-migrations : Do not offer to create the stream events table}';

    protected $description = 'Publish the Bridge config and HTML shell view';

    public function handle(Repository $config, DatabaseManager $db): int
    {
        $this->call('vendor:publish', ['--tag' => 'bridge-config', '--force' => (bool) $this->option('force')]);
        $this->call('vendor:publish', ['--tag' => 'bridge-views', '--force' => (bool) $this->option('force')]);

        if (! $this->option('without-migrations')) {
            $this->migrateStreamTable($config, $db);
        }

        $this->components->info('Bridge installed.');
        $this->components->bulletList([
            'config/bridge.php published; set shell.view to "app" to use the published resources/views/app.blade.php.',
            'The `bridge` middleware is added to the `web` group automatically (bridge.middleware.auto_register); run bridge:middleware for your own subclass.',
            'Return Bridge::render(\'Component\', [...]) from controllers; content negotiation picks HTML, page or JSON.',
            'For bearer-token clients on web routes, swap VerifyCsrfToken for Bridge\Http\Middleware\VerifyCsrfToken.',
        ]);

        return self::SUCCESS;
    }

    /**
     * The `database` stream driver (the default) needs its table. The migration
     * is loaded from the package, so a plain `migrate` creates it.
     */
    private function migrateStreamTable(Repository $config, DatabaseManager $db): void
    {
        if ($config->get('bridge.stream.driver') !== 'database') {
            return;
        }

        $connection = $config->get('bridge.stream.drivers.database.connection');
        $table = (string) $config->get('bridge.stream.drivers.database.table', 'bridge_stream_events');

        try {
            if ($db->connection(is_string($connection) ? $connection : null)->getSchemaBuilder()->hasTable($table)) {
                return;
            }
        } catch (Throwable $e) {
            $this->components->warn("The database stream driver needs the [{$table}] table, but the database is not reachable ({$e->getMessage()}). Run `php artisan migrate` once it is.");

            return;
        }

        if ($this->components->confirm("The database stream driver needs the [{$table}] table. Run `php artisan migrate` now?", true)) {
            $this->call('migrate');
        } else {
            $this->components->warn('Run `php artisan migrate` before opening streams, or set BRIDGE_STREAM_DRIVER to another driver.');
        }
    }
}
