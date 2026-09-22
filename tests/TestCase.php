<?php

declare(strict_types=1);

namespace Bridge\Tests;

use Bridge\BridgeServiceProvider;
use Bridge\Negotiation\Mode;
use Illuminate\Foundation\Application;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const PAGE_ACCEPT = Mode::PAGE_MEDIA_TYPE.'; v=1';

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BridgeServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
        $app['config']->set('app.debug', false);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('view.paths', [__DIR__.'/Fixtures/views']);
        $app['config']->set('bridge.build.version', 'test-build');
    }

    protected function page(string $uri, array $headers = []): TestResponse
    {
        return $this->withHeaders(['Accept' => self::PAGE_ACCEPT] + $headers)->get($uri);
    }

    protected function json_mode(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars(['Accept' => 'application/json'] + $headers);

        return $this->call($method, $uri, $data, [], [], $server);
    }

    protected function html(string $uri, array $headers = []): TestResponse
    {
        return $this->withHeaders(['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'] + $headers)->get($uri);
    }
}
