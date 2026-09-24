<?php

declare(strict_types=1);

namespace Bridge;

use Bridge\Console\DoctorCommand;
use Bridge\Console\InstallCommand;
use Bridge\Console\PruneStreamEventsCommand;
use Bridge\Console\SsrCommand;
use Bridge\Errors\ErrorMapper;
use Bridge\Errors\ExceptionRenderer;
use Bridge\Http\Middleware\AuthenticateStreamTicket;
use Bridge\Http\Middleware\HandleBridgeRequests;
use Bridge\Negotiation\ContentNegotiator;
use Bridge\Negotiation\Mode;
use Bridge\Negotiation\Negotiation;
use Bridge\Props\PropResolver;
use Bridge\Props\Serializer;
use Bridge\Representation\RepresenterRegistry;
use Bridge\Ssr\HttpSsrGateway;
use Bridge\Ssr\NullSsrGateway;
use Bridge\Ssr\SsrGateway;
use Bridge\Stream\Bus\BusManager;
use Bridge\Stream\ChannelAuthorizer;
use Bridge\Stream\ConnectionLimiter;
use Bridge\Stream\Contracts\EventBus;
use Bridge\Stream\Listeners\PublishStreamableEvents;
use Bridge\Support\Version;
use Bridge\Testing\BridgeTestingMacros;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Psr\Log\LoggerInterface;

final class BridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bridge.php', 'bridge');

        $this->app->singleton(Version::class, function (Application $app): Version {
            $configured = $app->make(Repository::class)->get('bridge.build.version');
            $manifest = $app->make(Repository::class)->get('bridge.build.manifest');
            $manifest = is_string($manifest) && $manifest !== '' ? $manifest : $app->publicPath('build/manifest.json');

            $version = new Version($manifest);

            if (is_string($configured) && $configured !== '') {
                $version->set($configured);
            }

            return $version;
        });

        $this->app->singleton(Bridge::class, fn (Application $app): Bridge => new Bridge($app, $app->make(Version::class)));

        $this->app->singleton(ContentNegotiator::class, function (Application $app): ContentNegotiator {
            $default = Mode::tryFrom((string) $app->make(Repository::class)->get('bridge.negotiation.default_mode', 'html')) ?? Mode::Html;

            return new ContentNegotiator((int) $app->make(Repository::class)->get('bridge.protocol.max_version', 1), $default);
        });

        $this->app->singleton(Serializer::class, fn (Application $app): Serializer => new Serializer($app));

        $this->app->singleton(PropResolver::class, fn (Application $app): PropResolver => new PropResolver(
            $app,
            $app->make(Serializer::class),
            (bool) $app->make(Repository::class)->get('bridge.json.resolve_deferred', true),
        ));

        $this->app->singleton(RepresenterRegistry::class, fn (Application $app): RepresenterRegistry => new RepresenterRegistry($app));
        $this->app->singleton(ErrorMapper::class);
        $this->app->singleton(ExceptionRenderer::class);

        $this->app->singleton(SsrGateway::class, function (Application $app): SsrGateway {
            $config = $app->make(Repository::class);

            if (! (bool) $config->get('bridge.ssr.enabled', false)) {
                return new NullSsrGateway;
            }

            return new HttpSsrGateway(
                $app->make(HttpFactory::class),
                $app->make(LoggerInterface::class),
                (string) $config->get('bridge.ssr.url', 'http://127.0.0.1:13714'),
                (float) $config->get('bridge.ssr.timeout', 2.0),
            );
        });

        $this->app->singleton(BusManager::class, fn (Application $app): BusManager => new BusManager($app));
        $this->app->bind(EventBus::class, fn (Application $app): EventBus => $app->make(BusManager::class)->driver());
        $this->app->singleton(ChannelAuthorizer::class, fn (Application $app): ChannelAuthorizer => new ChannelAuthorizer($app));
        $this->app->singleton(ConnectionLimiter::class, function (Application $app): ConnectionLimiter {
            $config = $app->make(Repository::class);
            $maxDuration = $config->get('bridge.stream.max_duration_s');

            return new ConnectionLimiter(
                $app->make(CacheFactory::class)->store(),
                (int) $config->get('bridge.stream.max_connections_per_user', 3),
                (is_numeric($maxDuration) ? (int) $maxDuration : 300) + 60,
            );
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'bridge');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([__DIR__.'/../config/bridge.php' => $this->app->configPath('bridge.php')], 'bridge-config');
        $this->publishes([__DIR__.'/../resources/views/app.blade.php' => $this->app->resourcePath('views/app.blade.php')], 'bridge-views');

        $this->registerMiddleware();
        $this->registerRateLimiter();
        $this->registerExceptionRenderer();
        $this->registerBladeDirectives();
        $this->registerRequestMacros();
        $this->registerDefaultSharedProps();
        $this->app->booted(fn (Application $app) => $app->make(Bridge::class)->freezeBootShared());
        $this->app->make(Dispatcher::class)->listen(RequestHandled::class, fn () => $this->app->make(Bridge::class)->resetRequestShared());

        $this->app->make(Dispatcher::class)->listen('*', PublishStreamableEvents::class);

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, DoctorCommand::class, PruneStreamEventsCommand::class, SsrCommand::class]);
        }

        if (class_exists(TestResponse::class)) {
            BridgeTestingMacros::register();
        }
    }

    private function registerMiddleware(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('bridge', HandleBridgeRequests::class);
        $router->aliasMiddleware('bridge.ticket', AuthenticateStreamTicket::class);

        $autoRegister = (bool) $this->app->make(Repository::class)->get('bridge.middleware.auto_register', true);

        // The HTTP kernel re-syncs its middleware groups and priority to the
        // router when it is constructed, so change them through the kernel.
        $this->callAfterResolving(HttpKernel::class, function (HttpKernel $kernel) use ($autoRegister): void {
            // Tickets must authenticate before `auth:*` runs. Laravel lists the
            // AuthenticatesRequests contract in its priority list, not the class.
            if (method_exists($kernel, 'addToMiddlewarePriorityBefore') && method_exists($kernel, 'getMiddlewarePriority')) {
                $priority = $kernel->getMiddlewarePriority();
                $before = in_array(AuthenticatesRequests::class, $priority, true) ? AuthenticatesRequests::class : Authenticate::class;
                $kernel->addToMiddlewarePriorityBefore($before, AuthenticateStreamTicket::class);
            }

            if ($autoRegister && method_exists($kernel, 'appendMiddlewareToGroup')) {
                $kernel->appendMiddlewareToGroup('web', HandleBridgeRequests::class);
            }
        });
    }

    /**
     * `throttle:bridge-stream` for stream routes: limits connection attempts,
     * complementing the per-user concurrent connection cap.
     */
    private function registerRateLimiter(): void
    {
        $perMinute = (int) $this->app->make(Repository::class)->get('bridge.stream.connects_per_minute', 30);

        RateLimiter::for('bridge-stream', function (Request $request) use ($perMinute) {
            $user = $request->user();
            $key = $user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip();

            return Limit::perMinute($perMinute)->by('bridge-stream:'.$key);
        });
    }

    private function registerExceptionRenderer(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (method_exists($handler, 'renderable')) {
                $handler->renderable($this->app->make(ExceptionRenderer::class));
            }
        });
    }

    private function registerBladeDirectives(): void
    {
        // <x-bridge::app /> and <x-bridge::head />: component alternatives to the directives.
        Blade::componentNamespace('Bridge\\View\\Components', 'bridge');

        $rootId = (string) $this->app->make(Repository::class)->get('bridge.shell.root_id', 'app');

        Blade::directive('bridge', function (?string $expression) use ($rootId): string {
            $id = $expression !== null && trim($expression) !== '' ? $expression : var_export($rootId, true);

            return '<?php echo \\Bridge\\Support\\Shell::root('.$id.', $bridgePage ?? null, $bridgeSsrBody ?? null); ?>';
        });

        Blade::directive('bridgeHead', fn (): string => '<?php echo \\Bridge\\Support\\Shell::head($bridgeProtocol ?? 1, $bridgeBuild ?? null, $bridgeSsrHead ?? []); ?>');
    }

    private function registerRequestMacros(): void
    {
        Request::macro('bridge', function (): Negotiation {
            /** @var Request $this */
            return Negotiation::for($this);
        });

        Request::macro('bridgeMode', function (): Mode {
            /** @var Request $this */
            return Negotiation::for($this)->mode;
        });
    }

    private function registerDefaultSharedProps(): void
    {
        $bridge = $this->app->make(Bridge::class);
        $flashKeys = $this->app->make(Repository::class)->get('bridge.flash.keys', ['message', 'level']);
        $flashKeys = is_array($flashKeys) ? array_values($flashKeys) : ['message', 'level'];

        $bridge->share('errors', function (Request $request): object {
            if (! $request->hasSession()) {
                return new \stdClass;
            }

            $errors = $request->session()->get('errors');

            if (! $errors instanceof ViewErrorBag || $errors->getBag('default')->isEmpty()) {
                return new \stdClass;
            }

            return (object) $errors->getBag('default')->getMessages();
        });

        $bridge->share('flash', function (Request $request) use ($flashKeys): ?array {
            if (! $request->hasSession()) {
                return null;
            }

            /** @var Session $session */
            $session = $request->session();
            $flash = [];

            foreach ($flashKeys as $key) {
                if ($session->has($key)) {
                    $flash[$key] = $session->get($key);
                }
            }

            return $flash === [] ? null : $flash;
        });
    }
}
