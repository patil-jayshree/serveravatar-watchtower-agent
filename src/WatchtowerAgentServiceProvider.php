<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower;

use Illuminate\Support\ServiceProvider;
use ServerAvatar\Watchtower\Client\WatchtowerClient;
use ServerAvatar\Watchtower\Contracts\WatchtowerClientInterface;
use ServerAvatar\Watchtower\Middleware\WatchtowerRequestTelemetry;
use ServerAvatar\Watchtower\Services\ConnectionVerifier;
use ServerAvatar\Watchtower\Services\RequestSanitizer;
use ServerAvatar\Watchtower\Services\RequestTelemetry;

class WatchtowerAgentServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__ . '/Config/watchtower.php',
            'watchtower'
        );

        // Bind the WatchtowerClient interface to the implementation
        $this->app->singleton(WatchtowerClientInterface::class, function ($app) {
            return new WatchtowerClient(
                baseUrl: config('watchtower.url', ''),
                token: config('watchtower.token', ''),
                connectionSettings: [
                    'timeout' => config('watchtower.connection.timeout', 30),
                    'connect_timeout' => config('watchtower.connection.connect_timeout', 10),
                    'retry_enabled' => config('watchtower.connection.retry_enabled', true),
                    'retry_attempts' => config('watchtower.connection.retry_attempts', 3),
                    'retry_delay' => config('watchtower.connection.retry_delay', 1000),
                ],
                agentVersion: config('watchtower.agent_version', '1.0.0')
            );
        });

        // Bind ConnectionVerifier
        $this->app->singleton(ConnectionVerifier::class, function ($app) {
            return new ConnectionVerifier(
                $app->make(WatchtowerClientInterface::class)
            );
        });

        // Bind RequestSanitizer
        $this->app->singleton(RequestSanitizer::class, function () {
            return new RequestSanitizer();
        });

        // Bind RequestTelemetry
        $this->app->singleton(RequestTelemetry::class, function ($app) {
            return new RequestTelemetry(
                $app->make(RequestSanitizer::class),
                $app->make(WatchtowerClientInterface::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register the Artisan command
        if ($this->app->runningInConsole()) {
            $this->commands([
                \ServerAvatar\Watchtower\Commands\WatchtowerStatusCommand::class,
            ]);
        }

        // Register the request telemetry middleware
        $router = $this->app->make('router');
        $router->pushMiddlewareToGroup('web', WatchtowerRequestTelemetry::class);
        $router->pushMiddlewareToGroup('api', WatchtowerRequestTelemetry::class);
    }

        // Publish configuration file
        $this->publishes([
            __DIR__ . '/Config/watchtower.php' => config_path('watchtower.php'),
        ], 'watchtower-config');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            WatchtowerClientInterface::class,
            ConnectionVerifier::class,
        ];
    }
}
