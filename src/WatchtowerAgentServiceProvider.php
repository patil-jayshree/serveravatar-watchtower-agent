<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower;

use Illuminate\Support\ServiceProvider;
use ServerAvatar\Watchtower\Client\WatchtowerClient;
use ServerAvatar\Watchtower\Contracts\WatchtowerClientInterface;
use ServerAvatar\Watchtower\Middleware\WatchtowerRequestTelemetry;
use ServerAvatar\Watchtower\Services\CommandSanitizer;
use ServerAvatar\Watchtower\Services\CommandTelemetry;
use ServerAvatar\Watchtower\Services\ConnectionVerifier;
use ServerAvatar\Watchtower\Services\ExceptionSanitizer;
use ServerAvatar\Watchtower\Services\ExceptionTelemetry;
use ServerAvatar\Watchtower\Services\JobSanitizer;
use ServerAvatar\Watchtower\Services\JobTelemetry;
use ServerAvatar\Watchtower\Services\LogSanitizer;
use ServerAvatar\Watchtower\Services\LogTelemetry;
use ServerAvatar\Watchtower\Services\QuerySanitizer;
use ServerAvatar\Watchtower\Services\QueryTelemetry;
use ServerAvatar\Watchtower\Services\RequestSanitizer;
use ServerAvatar\Watchtower\Services\RequestTelemetry;
use ServerAvatar\Watchtower\Services\SchedulerMonitor;

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

        // Bind WatchtowerConfig
        $this->app->singleton(WatchtowerConfig::class, function () {
            return new WatchtowerConfig(config('watchtower', []));
        });

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

        // Bind ExceptionSanitizer
        $this->app->singleton(ExceptionSanitizer::class, function () {
            return new ExceptionSanitizer();
        });

        // Bind ExceptionTelemetry
        $this->app->singleton(ExceptionTelemetry::class, function ($app) {
            return new ExceptionTelemetry(
                $app->make(ExceptionSanitizer::class),
                $app->make(WatchtowerClientInterface::class)
            );
        });

        // Bind QuerySanitizer
        $this->app->singleton(QuerySanitizer::class, function () {
            return new QuerySanitizer();
        });

        // Bind QueryTelemetry
        $this->app->singleton(QueryTelemetry::class, function ($app) {
            return new QueryTelemetry(
                $app->make(QuerySanitizer::class),
                $app->make(WatchtowerClientInterface::class)
            );
        });

        // Bind JobSanitizer
        $this->app->singleton(JobSanitizer::class, function () {
            return new JobSanitizer();
        });

        // Bind JobTelemetry
        $this->app->singleton(JobTelemetry::class, function ($app) {
            return new JobTelemetry(
                $app->make(JobSanitizer::class),
                $app->make(WatchtowerClientInterface::class)
            );
        });

        // Bind LogSanitizer
        $this->app->singleton(LogSanitizer::class, function () {
            return new LogSanitizer();
        });

        // Bind LogTelemetry
        $this->app->singleton(LogTelemetry::class, function ($app) {
            return new LogTelemetry(
                $app->make(LogSanitizer::class)
            );
        });

        // Bind CommandSanitizer
        $this->app->singleton(CommandSanitizer::class, function () {
            return new CommandSanitizer();
        });

        // Bind CommandTelemetry
        $this->app->singleton(CommandTelemetry::class, function ($app) {
            return new CommandTelemetry(
                $app->make(CommandSanitizer::class),
                $app->make(WatchtowerClientInterface::class),
                $app->make(SchedulerMonitor::class)
            );
        });

        // Bind SchedulerMonitor
        $this->app->singleton(SchedulerMonitor::class, function ($app) {
            return new SchedulerMonitor(
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

        // Register the exception handler
        $this->registerExceptionHandler();

        // Enable query monitoring if configured
        $this->registerQueryMonitoring();

        // Enable queue monitoring if configured
        $this->registerQueueMonitoring();

        // Enable log monitoring if configured
        $this->registerLogMonitoring();

        // Enable command monitoring if configured
        $this->registerCommandMonitoring();

        // Enable scheduler monitoring if configured
        $this->registerSchedulerMonitoring();

        // Publish configuration file
        $this->publishes([
            __DIR__ . '/Config/watchtower.php' => config_path('watchtower.php'),
        ], 'watchtower-config');
    }

    /**
     * Register query monitoring.
     */
    protected function registerQueryMonitoring(): void
    {
        if (! config('watchtower.enabled', true) || ! config('watchtower.query_monitoring.enabled', false)) {
            return;
        }

        // Resolve QueryTelemetry and enable it
        $queryTelemetry = $this->app->make(\ServerAvatar\Watchtower\Services\QueryTelemetry::class);
        $queryTelemetry->enable();

        // Also hook into the middleware to propagate request ID
        $this->app->resolving(\ServerAvatar\Watchtower\Middleware\WatchtowerRequestTelemetry::class, function (
            \ServerAvatar\Watchtower\Middleware\WatchtowerRequestTelemetry $middleware
        ) use ($queryTelemetry) {
            // When the middleware sets request ID, also set it for query telemetry
            $middleware->setRequestIdCallback(function (?string $requestId) use ($queryTelemetry) {
                $queryTelemetry->setCurrentRequestId($requestId);
            });
        });
    }

    /**
     * Register queue monitoring.
     */
    protected function registerQueueMonitoring(): void
    {
        if (! config('watchtower.enabled', true) || ! config('watchtower.queue_monitoring.enabled', false)) {
            return;
        }

        // Resolve JobTelemetry and enable it
        $jobTelemetry = $this->app->make(\ServerAvatar\Watchtower\Services\JobTelemetry::class);
        $jobTelemetry->enable();

        // Also hook into the middleware to propagate request/trace ID
        $this->app->resolving(\ServerAvatar\Watchtower\Middleware\WatchtowerRequestTelemetry::class, function (
            \ServerAvatar\Watchtower\Middleware\WatchtowerRequestTelemetry $middleware
        ) use ($jobTelemetry) {
            // When the middleware sets request ID, also set it for job telemetry
            $middleware->setRequestIdCallback(function (?string $requestId) use ($jobTelemetry) {
                $jobTelemetry->setCurrentRequestId($requestId);
            });
        });
    }

    /**
     * Register log monitoring.
     */
    protected function registerLogMonitoring(): void
    {
        if (! config('watchtower.enabled', true) || ! config('watchtower.log_monitoring.enabled', false)) {
            return;
        }

        // Resolve LogTelemetry and inject the WatchtowerClient
        $logTelemetry = $this->app->make(\ServerAvatar\Watchtower\Services\LogTelemetry::class);
        $client = $this->app->make(WatchtowerClientInterface::class);
        $logTelemetry->setClient($client);

        // Create and register the Monolog handler
        $handler = $logTelemetry->createHandler();

        // Add to the default log channel
        $logger = $this->app->make('log');
        $logger->getLogger()->pushHandler($handler);
    }

    /**
     * Register command monitoring.
     */
    protected function registerCommandMonitoring(): void
    {
        if (! config('watchtower.enabled', true) || ! config('watchtower.command_monitoring.enabled', false)) {
            return;
        }

        // Resolve CommandTelemetry and enable it
        $commandTelemetry = $this->app->make(\ServerAvatar\Watchtower\Services\CommandTelemetry::class);
        $commandTelemetry->enable();
    }

    /**
     * Register scheduler monitoring.
     */
    protected function registerSchedulerMonitoring(): void
    {
        if (! config('watchtower.enabled', true) || ! config('watchtower.scheduler_monitoring.enabled', false)) {
            return;
        }

        // Resolve SchedulerMonitor and enable it
        $schedulerMonitor = $this->app->make(\ServerAvatar\Watchtower\Services\SchedulerMonitor::class);
        $schedulerMonitor->enable();
    }

    /**
     * Register the exception handler to capture exceptions.
     */
    protected function registerExceptionHandler(): void
    {
        // Only register if explicitly enabled
        if (! config('watchtower.exceptions.enabled', true)) {
            return;
        }

        $this->app->make('events')->listen('*', function ($event, $data = []) {
            // This is a fallback - the actual exception capture happens in
            // the WatchtowerExceptionHandler middleware
        });
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
