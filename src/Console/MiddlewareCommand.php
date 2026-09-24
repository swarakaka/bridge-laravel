<?php

declare(strict_types=1);

namespace Bridge\Console;

use Illuminate\Console\GeneratorCommand;

final class MiddlewareCommand extends GeneratorCommand
{
    protected $signature = 'bridge:middleware
        {name=HandleBridgeRequests : The name of the middleware}
        {--force : Overwrite the middleware if it exists}';

    protected $description = 'Create an application middleware extending HandleBridgeRequests';

    protected $type = 'Middleware';

    public function handle(): ?bool
    {
        if (parent::handle() === false) {
            return false;
        }

        $this->components->bulletList([
            'Append it to the `web` group in bootstrap/app.php: $middleware->web(append: [\\'.$this->qualifyClass($this->getNameInput()).'::class]);',
            'Bridge then stops adding its own middleware to `web` (bridge.middleware.auto_register).',
        ]);

        return null;
    }

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/middleware.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Http\Middleware';
    }
}
