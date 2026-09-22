<?php

declare(strict_types=1);

it('publishes config and views', function () {
    $this->artisan('bridge:install', ['--force' => true])->assertSuccessful();

    expect(file_exists(config_path('bridge.php')))->toBeTrue()
        ->and(file_exists(resource_path('views/app.blade.php')))->toBeTrue();

    @unlink(config_path('bridge.php'));
    @unlink(resource_path('views/app.blade.php'));
});
