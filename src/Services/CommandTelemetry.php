<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Services;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Str;
use ServerAvatar\Watchtower\Contracts\WatchtowerClientInterface;
use ServerAvatar\Watchtower\Data\CommandData;
use Throwable;

class CommandTelemetry
{
    /**
     * Whether command monitoring is enabled.
     */
    protected bool $enabled = false;

    /**
     * Whether listeners are registered.
     */
    protected bool $listenersRegistered = false;

    /**
     * Command start times for duration calculation (nanoseconds from hrtime).
     *
     * @var array<string, array{started_at: int|float, request_id: ?string}>
     */
    protected array $commandStartTimes = [];

    /**
     * Ignored command names/patterns.
     *
     * @var array<string>
     */
    protected array $ignoredCommands = [
        'list',
        'help',
        'version',
        '_complete',
        'completion',
        'envoy:run',
    ];

    public function __construct(
        protected CommandSanitizer $sanitizer,
        protected WatchtowerClientInterface $client,
    ) {}

    /**
     * Enable command monitoring.
     */
    public function enable(): void
    {
        if (! config('watchtower.enabled', true) || ! config('watchtower.command_monitoring.enabled', false)) {
            return;
        }

        $this->enabled = true;
        $this->registerListeners();
    }

    /**
     * Disable command monitoring.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Check if a command should be ignored.
     */
    protected function shouldIgnore(string $commandName): bool
    {
        $commandName = strtolower($commandName);

        foreach ($this->ignoredCommands as $ignored) {
            if ($commandName === $ignored || str_starts_with($commandName, $ignored . ' ')) {
                return true;
            }
        }

        // Check configured ignored commands
        $configured = config('watchtower.command_monitoring.ignored_commands', []);
        foreach ($configured as $ignored) {
            if ($commandName === strtolower($ignored) || str_contains($commandName, strtolower($ignored))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Register console event listeners.
     */
    protected function registerListeners(): void
    {
        if ($this->listenersRegistered) {
            return;
        }

        $self = $this;

        // CommandStarting - when a command begins execution
        $this->appEvents()->listen(CommandStarting::class, function (CommandStarting $event) use ($self) {
            if (! $self->enabled) {
                return;
            }

            $self->handleCommandStarting($event);
        });

        // CommandFinished - when a command finishes (success or failure)
        $this->appEvents()->listen(CommandFinished::class, function (CommandFinished $event) use ($self) {
            if (! $self->enabled) {
                return;
            }

            $self->handleCommandFinished($event);
        });

        $this->listenersRegistered = true;
    }

    /**
     * Get the application event dispatcher.
     */
    protected function appEvents()
    {
        return app('events');
    }

    /**
     * Handle CommandStarting event.
     */
    protected function handleCommandStarting(CommandStarting $event): void
    {
        $commandName = $event->command ?? 'unknown';

        // Check if command should be ignored
        if ($this->shouldIgnore($commandName)) {
            return;
        }

        $commandUuid = 'cmd_' . Str::random(16);
        $startedAtNs = hrtime(true); // nanoseconds for precise duration
        $startedAtUnix = time(); // Unix timestamp for schema compatibility

        // Extract sanitized arguments and options from input
        $arguments = $this->extractArguments($event->input);
        $options = $this->extractOptions($event->input);

        // Get current request ID if running in HTTP context
        $requestId = $this->getCurrentRequestId();

        // Store start time (nanoseconds) for precise duration calculation
        // Also store Unix timestamp for schema compatibility
        $this->commandStartTimes[$commandUuid] = [
            'started_at_ns' => $startedAtNs,
            'started_at_unix' => $startedAtUnix,
            'request_id' => $requestId,
            'command_name' => $commandName,
            'arguments' => $arguments,
            'options' => $options,
        ];

        $data = new CommandData(
            commandUuid: $commandUuid,
            commandName: $commandName,
            status: 'started',
            exitCode: null,
            durationMs: null,
            startedAt: $startedAtUnix,
            finishedAt: null,
            requestId: $requestId,
            exceptionClass: null,
            exceptionMessage: null,
            exceptionFile: null,
            exceptionLine: null,
            stackTrace: null,
            arguments: $arguments,
            options: $options,
            environment: config('watchtower.environment', 'production'),
            agentVersion: config('watchtower.agent_version', '1.0.0'),
            laravelVersion: app()->version(),
            phpVersion: PHP_VERSION,
            serverName: gethostname(),
        );

        $this->sendTelemetry($data->toArray());
    }

    /**
     * Handle CommandFinished event.
     */
    protected function handleCommandFinished(CommandFinished $event): void
    {
        $commandName = $event->command ?? 'unknown';

        // Check if command should be ignored
        if ($this->shouldIgnore($commandName)) {
            return;
        }

        $exitCode = $event->exitCode;
        $finishedAtNs = hrtime(true); // nanoseconds for precise duration
        $finishedAtUnix = time(); // Unix timestamp for schema compatibility

        // Find the matching started command by name
        $commandUuid = null;
        $storedData = null;
        foreach ($this->commandStartTimes as $uuid => $data) {
            if ($data['command_name'] === $commandName) {
                $commandUuid = $uuid;
                $storedData = $data;
                unset($this->commandStartTimes[$uuid]);
                break;
            }
        }

        // If we didn't capture CommandStarting (e.g., already finished before we registered),
        // create minimal data
        if ($commandUuid === null) {
            $commandUuid = 'cmd_' . Str::random(16);
            $finishedAtUnix = time();
            $storedData = [
                'started_at_ns' => $finishedAtNs,
                'started_at_unix' => $finishedAtUnix,
                'request_id' => $this->getCurrentRequestId(),
                'command_name' => $commandName,
                'arguments' => [],
                'options' => [],
            ];
        }

        // Calculate duration in milliseconds with nanosecond precision
        $durationMs = null;
        if ($storedData && isset($storedData['started_at_ns'])) {
            $durationMs = (int) (($finishedAtNs - $storedData['started_at_ns']) / 1_000_000);
        }

        // Determine status
        $status = $exitCode === 0 ? 'completed' : 'failed';

        // Check slow threshold
        $slowThreshold = (int) config('watchtower.command_monitoring.slow_threshold_ms', 1000);
        if ($slowThreshold > 0 && $durationMs !== null && $durationMs >= $slowThreshold) {
            // Mark as slow (still completed/failed, but slow flag is in data)
        }

        $data = new CommandData(
            commandUuid: $commandUuid,
            commandName: $commandName,
            status: $status,
            exitCode: $exitCode,
            durationMs: $durationMs !== null ? (int) $durationMs : null,
            startedAt: $storedData['started_at_unix'] ?? $finishedAtUnix,
            finishedAt: $finishedAtUnix,
            requestId: $storedData['request_id'] ?? null,
            exceptionClass: null,
            exceptionMessage: null,
            exceptionFile: null,
            exceptionLine: null,
            stackTrace: null,
            arguments: $storedData['arguments'] ?? [],
            options: $storedData['options'] ?? [],
            environment: config('watchtower.environment', 'production'),
            agentVersion: config('watchtower.agent_version', '1.0.0'),
            laravelVersion: app()->version(),
            phpVersion: PHP_VERSION,
            serverName: gethostname(),
        );

        $this->sendTelemetry($data->toArray());
    }

    /**
     * Extract sanitized arguments from input.
     *
     * @return array<string, mixed>
     */
    protected function extractArguments($input): array
    {
        if (! is_object($input) || ! method_exists($input, 'getArguments')) {
            return [];
        }

        try {
            $arguments = $input->getArguments();
            if (! is_array($arguments)) {
                return [];
            }

            $sanitized = [];
            foreach ($arguments as $argument) {
                if (! is_array($argument) || ! isset($argument['name'])) {
                    continue;
                }
                $name = $argument['name'];
                $value = $argument['value'] ?? null;

                // Skip the implicit "command" argument
                if ($name === 'command') {
                    continue;
                }

                $sanitized[$name] = $value;
            }

            return $this->sanitizer->sanitizeArguments($sanitized);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Extract sanitized options from input.
     *
     * @return array<string, mixed>
     */
    protected function extractOptions($input): array
    {
        if (! is_object($input) || ! method_exists($input, 'getOptions')) {
            return [];
        }

        try {
            $options = $input->getOptions();
            if (! is_array($options)) {
                return [];
            }

            // Filter to only options that were explicitly set
            $setOptions = [];
            foreach ($options as $key => $value) {
                // Boolean options that are false weren't explicitly set
                if ($value === false) {
                    continue;
                }
                $setOptions[$key] = $value;
            }

            return $this->sanitizer->sanitizeOptions($setOptions);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Get the current request ID from the middleware context.
     */
    protected function getCurrentRequestId(): ?string
    {
        // Try to get from the current request if in HTTP context
        try {
            if (app()->runningInConsole()) {
                // Check if there's an active request even in console
                $request = app('request');
                if ($request && $request->hasHeader('X-Watchtower-Request-Id')) {
                    return $request->header('X-Watchtower-Request-Id');
                }
            }
        } catch (Throwable $e) {
            // Ignore
        }

        return null;
    }

    /**
     * Send telemetry to Watchtower.
     *
     * @param array<string, mixed> $data
     */
    protected function sendTelemetry(array $data): void
    {
        // Sanitize the data
        $data = $this->sanitizer->sanitize($data);

        $timeout = (int) config('watchtower.command_monitoring.timeout', 3);

        try {
            $this->client->sendCommandTelemetry($data, $timeout);
        } catch (Throwable $e) {
            // Never let telemetry errors affect command execution
            if (config('watchtower.debug', false)) {
                logger()->debug('Watchtower command telemetry failed', [
                    'error' => $e->getMessage(),
                    'command' => $data['command_name'] ?? 'unknown',
                ]);
            }
        }
    }
}
