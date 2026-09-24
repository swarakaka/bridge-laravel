<?php

declare(strict_types=1);

namespace Bridge\Console;

use Bridge\Stream\Bus\BusManager;
use Bridge\Stream\Bus\DatabaseBus;
use Bridge\Stream\Bus\Envelope;
use Bridge\Stream\Bus\RedisStreamsBus;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks the runtime for stream readiness: PHP output settings, the bus, and
 * optionally an end-to-end request that measures time-to-first-byte.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'bridge:doctor {--url= : A stream URL to probe over HTTP} {--token= : Bearer token for the probe}';

    protected $description = 'Diagnose Bridge stream (SSE) readiness';

    public function handle(Repository $config, BusManager $buses): int
    {
        $this->failures = 0;

        $this->components->info('PHP');
        $this->check('output_buffering is off or the stream disables it', in_array(ini_get('output_buffering'), ['', '0', false], true), 'Bridge disables buffering per request; a global "0" is still recommended.');
        $this->check('zlib.output_compression is off', in_array(ini_get('zlib.output_compression'), ['', '0', false], true), 'Compression buffers the stream; disable it for text/event-stream.');
        $this->check('max_execution_time', true, 'Current: '.ini_get('max_execution_time').' (the stream calls set_time_limit(0)).');
        $this->check('runtime', true, $this->laravel->bound('octane') ? 'Octane detected: default max_duration 300s' : 'Classic PHP: default max_duration 60s, one worker per open stream');

        $this->components->info('Bus');
        $driver = (string) $config->get('bridge.stream.driver');
        $this->components->twoColumnDetail('driver', $driver);

        try {
            $bus = $buses->driver();
            $channel = 'bridge.doctor.'.bin2hex(random_bytes(4));
            $cursor = $bus->latestCursor([$channel]);
            $id = $bus->publish([$channel], Envelope::make('bridge.doctor', ['ok' => true]));
            $received = iterator_to_array($this->toIterator($bus->read([$channel], $cursor, 100)));

            // Leave nothing behind: one stream key or row set per run would accumulate.
            if ($bus instanceof RedisStreamsBus || $bus instanceof DatabaseBus) {
                $bus->forget([$channel]);
            }
            $this->check("publish/read roundtrip via [{$driver}] (id {$id})", count($received) === 1);
            $this->check('replay supported', $bus->supportsReplay(), $bus->supportsReplay() ? 'Last-Event-ID replay available' : 'No replay: clients resync on reconnect');
        } catch (Throwable $e) {
            $this->check("bus [{$driver}] reachable", false, $this->hint($driver, $config) ?? $e->getMessage());
        }

        if (is_string($url = $this->option('url')) && $url !== '') {
            $this->components->info('HTTP probe');
            $this->probe($url, is_string($this->option('token')) ? $this->option('token') : null);
        }

        $this->newLine();
        $this->failures === 0 ? $this->components->info('Bridge streams look healthy.') : $this->components->warn('Some checks failed; see above.');

        return $this->failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private int $failures = 0;

    /** A missing table is the usual database-driver failure; say how to fix it. */
    private function hint(string $driver, Repository $config): ?string
    {
        if ($driver !== 'database') {
            return null;
        }

        $connection = $config->get('bridge.stream.drivers.database.connection');
        $table = (string) $config->get('bridge.stream.drivers.database.table', 'bridge_stream_events');

        try {
            return DB::connection(is_string($connection) ? $connection : null)->getSchemaBuilder()->hasTable($table)
                ? null
                : "Table [{$table}] is missing: run `php artisan migrate`.";
        } catch (Throwable) {
            return null;
        }
    }

    private function probe(string $url, ?string $token): void
    {
        $start = microtime(true);

        try {
            $request = Http::withHeaders(array_filter(['Accept' => 'text/event-stream', 'Authorization' => $token ? "Bearer {$token}" : null]))
                ->timeout(10)
                ->withOptions(['stream' => true]);
            $response = $request->get($url);
            $firstByteMs = (int) ((microtime(true) - $start) * 1000);
            $body = $response->toPsrResponse()->getBody();
            $chunk = $body->read(512);
            $chunkMs = (int) ((microtime(true) - $start) * 1000);

            $this->check('status 200 with text/event-stream', $response->status() === 200 && str_starts_with((string) $response->header('Content-Type'), 'text/event-stream'), $response->status().' '.$response->header('Content-Type'));
            $this->check('ready event arrives promptly', str_contains($chunk, '"type":"ready"') && $chunkMs < 3000, "first bytes after {$chunkMs} ms (headers after {$firstByteMs} ms); slow first bytes mean a buffering proxy");
            $this->check('X-Accel-Buffering: no', $response->header('X-Accel-Buffering') === 'no');
        } catch (Throwable $e) {
            $this->check('probe', false, $e->getMessage());
        }
    }

    private function check(string $label, bool $passed, ?string $detail = null): bool
    {
        $this->components->twoColumnDetail($label.($detail ? " <fg=gray>{$detail}</>" : ''), $passed ? '<fg=green>OK</>' : '<fg=red>FAIL</>');

        if (! $passed) {
            $this->failures++;
        }

        return $passed;
    }

    /**
     * @param  iterable<mixed>  $iterable
     * @return \Iterator<mixed>
     */
    private function toIterator(iterable $iterable): \Iterator
    {
        return $iterable instanceof \Iterator ? $iterable : new \ArrayIterator(is_array($iterable) ? $iterable : iterator_to_array($iterable));
    }
}
