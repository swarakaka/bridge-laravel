<?php

declare(strict_types=1);

namespace Bridge\Console;

use Bridge\Stream\Bus\BusManager;
use Bridge\Stream\Bus\DatabaseBus;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

final class PruneStreamEventsCommand extends Command
{
    protected $signature = 'bridge:stream:prune {--minutes= : Retain events newer than this many minutes}';

    protected $description = 'Delete old rows from the database stream bus';

    public function handle(BusManager $buses, Repository $config): int
    {
        $bus = $buses->driver('database');

        if (! $bus instanceof DatabaseBus) {
            $this->components->warn('The database bus is not configured.');

            return self::SUCCESS;
        }

        $minutes = (int) ($this->option('minutes') ?? $config->get('bridge.stream.drivers.database.retain_minutes', 60));
        $deleted = $bus->prune($minutes);
        $this->components->info("Pruned {$deleted} stream events older than {$minutes} minutes.");

        return self::SUCCESS;
    }
}
