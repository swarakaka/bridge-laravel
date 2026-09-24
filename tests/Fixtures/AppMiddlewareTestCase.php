<?php

declare(strict_types=1);

namespace Bridge\Tests\Fixtures;

use Bridge\Tests\Fixtures\Http\SharesProps;
use Bridge\Tests\TestCase;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;

/**
 * An application that appends its own HandleBridgeRequests subclass to the
 * `web` group, the way bootstrap/app.php does: before providers boot.
 */
abstract class AppMiddlewareTestCase extends TestCase
{
    protected function resolveApplicationHttpMiddlewares($app): void
    {
        parent::resolveApplicationHttpMiddlewares($app);

        $app->afterResolving(Kernel::class, function (HttpKernel $kernel): void {
            $kernel->appendMiddlewareToGroup('web', SharesProps::class);
        });
    }
}
