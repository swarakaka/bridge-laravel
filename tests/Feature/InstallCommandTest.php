<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

afterEach(function () {
    @unlink(config_path('bridge.php'));
    @unlink(resource_path('views/app.blade.php'));
});

it('publishes config and views', function () {
    $this->artisan('bridge:install', ['--force' => true, '--without-migrations' => true])->assertSuccessful();

    expect(file_exists(config_path('bridge.php')))->toBeTrue()
        ->and(file_exists(resource_path('views/app.blade.php')))->toBeTrue();
});

it('creates the stream events table for the database driver', function () {
    config()->set('bridge.stream.driver', 'database');
    expect(Schema::hasTable('bridge_stream_events'))->toBeFalse();

    $this->artisan('bridge:install', ['--force' => true])
        ->expectsConfirmation('The database stream driver needs the [bridge_stream_events] table. Run `php artisan migrate` now?', 'yes')
        ->assertSuccessful();

    expect(Schema::hasTable('bridge_stream_events'))->toBeTrue();
});

it('leaves the database alone when the migration is declined', function () {
    config()->set('bridge.stream.driver', 'database');

    $this->artisan('bridge:install', ['--force' => true])
        ->expectsConfirmation('The database stream driver needs the [bridge_stream_events] table. Run `php artisan migrate` now?', 'no')
        ->expectsOutputToContain('Run `php artisan migrate` before opening streams')
        ->assertSuccessful();

    expect(Schema::hasTable('bridge_stream_events'))->toBeFalse();
});

it('does not ask about the table for other drivers', function () {
    config()->set('bridge.stream.driver', 'redis');

    $this->artisan('bridge:install', ['--force' => true])->assertSuccessful();

    expect(Schema::hasTable('bridge_stream_events'))->toBeFalse();
});
