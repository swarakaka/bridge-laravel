<?php

declare(strict_types=1);

use Bridge\Http\Middleware\HandleBridgeRequests;
use Illuminate\Support\Facades\File;

afterEach(function () {
    File::delete([
        app_path('Http/Middleware/HandleBridgeRequests.php'),
        app_path('Http/Middleware/ShareBridgeProps.php'),
    ]);
});

it('generates an application middleware extending HandleBridgeRequests', function () {
    $this->artisan('bridge:middleware')
        ->expectsOutputToContain('App\Http\Middleware\HandleBridgeRequests::class')
        ->assertSuccessful();

    $contents = File::get(app_path('Http/Middleware/HandleBridgeRequests.php'));

    expect($contents)
        ->toContain('namespace App\Http\Middleware;')
        ->toContain('use Bridge\Http\Middleware\HandleBridgeRequests as Middleware;')
        ->toContain('class HandleBridgeRequests extends Middleware')
        ->not->toContain('{{');

    require_once app_path('Http/Middleware/HandleBridgeRequests.php');

    expect(is_subclass_of('App\\Http\\Middleware\\HandleBridgeRequests', HandleBridgeRequests::class))->toBeTrue()
        ->and(app('App\\Http\\Middleware\\HandleBridgeRequests')->share(request()))->toBe([]);
});

it('accepts another name', function () {
    $this->artisan('bridge:middleware', ['name' => 'ShareBridgeProps'])->assertSuccessful();

    expect(File::get(app_path('Http/Middleware/ShareBridgeProps.php')))
        ->toContain('class ShareBridgeProps extends Middleware');
});

it('keeps an existing middleware unless forced', function () {
    File::ensureDirectoryExists(app_path('Http/Middleware'));
    File::put(app_path('Http/Middleware/HandleBridgeRequests.php'), '<?php // mine');

    $this->artisan('bridge:middleware')->expectsOutputToContain('already exists');
    expect(File::get(app_path('Http/Middleware/HandleBridgeRequests.php')))->toBe('<?php // mine');

    $this->artisan('bridge:middleware', ['--force' => true])->assertSuccessful();
    expect(File::get(app_path('Http/Middleware/HandleBridgeRequests.php')))->toContain('extends Middleware');
});
