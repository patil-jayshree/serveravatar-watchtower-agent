<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Services;

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskMissed;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Str;
use ServerAvatar\Watchtower\Contracts\WatchtowerClientInterface;
use Throwable;

/**
 * Monitor Laravel Scheduler executions and report telemetry to Watchtower.
 *
 * All scheduled task events (Starting, Finished, Skipped, Missed) receive
 * an Illuminate\Console\Scheduling\Event object, not a ScheduledTask.
 */
class SchedulerMonitor
{
    /**
     * Offset to convert hrtime(true) nanoseconds to Unix timestamp.
     * hrtime(true) returns nanoseconds since an arbitrary epoch (not Unix epoch),
     * so we calibrate it against time() at startup.
     */
    protected float $hrtimeOffset = 0.0;

    /**
     * Whether scheduler monitoring is enabled.
     */
    protected bool $enabled = false;

    /**
     * Whether listeners are registered.
     */
    protected bool $listenersRegistered = false;

    /**
     * Currently running tasks keyed by execution UUID.
     *
     * @var array<string, array{
     *     task_name: string,
     *     task_type: string,
     *     command_name: ?string,
     *     job_name: ?string,
     *     job_uuid: ?string,
     *     expression: ?string,
     *     description: ?string,
     *     timezone: ?string,
     *     environment: ?string,
     *     expected_at: int,
     *     started_at_ns: int,
     *     command_uuid: ?string
     * }>
     */
    protected array $runningTasks = [];

    /**
     * Known scheduled tasks (task_name => task_data).
     *
     * @var array<string, array{
     *     task_name: string,
     *     task_type: string,
     *     command_name: ?string,
     *     job_name: ?string,
     *     job_uuid: ?string,
     *     expression: ?string,
     *     description: ?string,
     *     timezone: ?string,
     *     environment: ?string,
     *     next_run_at: ?int
     * }>
     */
    protected array $knownTasks = [];

    /**
     * Ignored task patterns.
     *
     * @var array<string>
     */
    protected array $ignoredTasks = [];

    /**
     * Grace period in minutes before marking a task as missed.
     */
    protected int $gracePeriodMinutes = 10;

    /**
     * Timeout for telemetry requests.
     */
    protected int $timeout = 3;

    public function __construct(
        protected WatchtowerClientInterface $client,
    ) {}

    /**
     * Enable scheduler monitoring.
     */
    public function enable(): void
    {
        if (! config('watchtower.enabled', true) || ! config('watchtower.scheduler_monitoring.enabled', false)) {
            return;
        }

        $this->enabled = true;
        $this->gracePeriodMinutes = (int) config('watchtower.scheduler_monitoring.grace_period_minutes', 10);
        $this->timeout = (int) config('watchtower.scheduler_monitoring.timeout', 3);
        $this->ignoredTasks = config('watchtower.scheduler_monitoring.ignored_tasks', []);

        // Calibrate hrtime to Unix epoch offset
        $this->hrtimeOffset = (float) time() - (hrtime(true) / 1_000_000_000);

        $this->registerListeners();
    }

    /**
     * Disable scheduler monitoring.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Check if a task should be ignored.
     */
    protected function shouldIgnore(string $taskName): bool
    {
        $taskName = strtolower($taskName);

        foreach ($this->ignoredTasks as $ignored) {
            if ($taskName === strtolower($ignored) || str_contains($taskName, strtolower($ignored))) {
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

        $this->appEvents()->listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) use ($self) {
            if (! $self->enabled) {
                return;
            }
            $self->handleScheduledTaskStarting($event);
        });

        $this->appEvents()->listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) use ($self) {
            if (! $self->enabled) {
                return;
            }
            $self->handleScheduledTaskFinished($event);
        });

        $this->appEvents()->listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event) use ($self) {
            if (! $self->enabled) {
                return;
            }
            $self->handleScheduledTaskSkipped($event);
        });

        $this->appEvents()->listen(ScheduledTaskMissed::class, function (ScheduledTaskMissed $event) use ($self) {
            if (! $self->enabled) {
                return;
            }
            $self->handleScheduledTaskMissed($event);
        });

        $this->appEvents()->listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) use ($self) {
            if (! $self->enabled) {
                return;
            }
            $self->handleScheduledTaskFailed($event);
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
     * Get the task name from an Event.
     * The 'command' property contains the full shell command, e.g.
     * '/usr/bin/php8.3 artisan test:scheduled --message="..."'
     */
    protected function getTaskNameFromEvent(Event $event): string
    {
        if (! empty($event->command)) {
            $command = $event->command;

            // Find artisan command name after 'artisan'
            // Handles: '/usr/bin/php artisan test:scheduled' or '/usr/bin/php8.3' 'artisan' test:scheduled
            if (preg_match('/artisan[\s\'"]+([a-z][a-z0-9_:\-\/]+)/i', $command, $matches)) {
                return $matches[1];
            }

            // Fallback: look for command-like pattern (letter, then word chars, then : or -- or end)
            if (preg_match('/([a-z][a-z0-9_:\-\/]+)(?:\s|--|\z)/i', $command, $matches)) {
                return $matches[1];
            }

            return $command;
        }

        if (! empty($event->description)) {
            return $event->description;
        }

        return 'unknown-event-task';
    }

    /**
     * Extract task information from an Event.
     *
     * @return array<string, mixed>
     */
    protected function extractTaskInfoFromEvent(Event $event): array
    {
        $taskName = $this->getTaskNameFromEvent($event);
        $commandName = null;
        $jobName = null;
        $jobUuid = null;
        $taskType = 'command';

        // The 'command' property has the full shell command like '/usr/bin/php artisan test:scheduled'
        if (! empty($event->command)) {
            $fullCommand = $event->command;

            // Extract just the artisan command portion
            if (preg_match('/artisan[\s\'"]+([a-z][a-z0-9_:\-\/]+)/i', $fullCommand, $matches)) {
                $commandName = $matches[1];
            }

            // If command name looks like a PHP class path, it's a job
            if ($commandName && (str_contains($commandName, '\\') || str_contains($commandName, '/'))) {
                $taskType = 'job';
                $jobName = $commandName;
                $commandName = null;
            }
        }

        return [
            'task_name' => $taskName,
            'task_type' => $taskType,
            'command_name' => $commandName,
            'job_name' => $jobName,
            'job_uuid' => $jobUuid,
            'expression' => $event->expression ?? null,
            'description' => $event->description ?? null,
            'timezone' => $event->timezone ?? config('app.timezone', 'UTC'),
            'environment' => 'production',
        ];
    }

    /**
     * Calculate the expected run time for a task based on its cron expression.
     */
    protected function calculateExpectedRunTime(Event $event): int
    {
        $expression = $event->expression ?? null;

        if (! $expression) {
            return time();
        }

        try {
            $tz = $event->timezone ? new \DateTimeZone($event->timezone) : null;
            $now = new \DateTime('now', $tz);

            return $this->getLastScheduledTime($expression, $now, $tz);
        } catch (\Throwable $e) {
            return time();
        }
    }

    /**
     * Calculate the next run time for a task.
     */
    protected function calculateNextRunTime(Event $event): ?int
    {
        $expression = $event->expression ?? null;

        if (! $expression) {
            return null;
        }

        try {
            $tz = $event->timezone ? new \DateTimeZone($event->timezone) : null;
            $now = new \DateTime('now', $tz);

            // Use dragonmantank/cron-expression v3.x if available
            if (class_exists('Cron\CronExpression') && method_exists('Cron\CronExpression', 'setTimezone')) {
                $cron = new \Cron\CronExpression($expression);
                $cron->setTimezone($tz ?? new \DateTimeZone('UTC'));

                return $cron->getNextRunDate($now)->getTimestamp();
            }

            return $this->parseNextRunSimple($expression, $now);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get the last scheduled time for a cron expression.
     */
    protected function getLastScheduledTime(string $expression, \DateTime $now, ?\DateTimeZone $tz): int
    {
        $parts = explode(' ', $expression);

        if (count($parts) < 5) {
            return time();
        }

        [$minute, $hour] = $parts;

        // Every minute - last was within the last minute
        if ($minute === '*') {
            return time();
        }

        if (str_starts_with($minute, '*/')) {
            $interval = (int) substr($minute, 2);
            $currentMinute = (int) $now->format('i');
            $minutesAgo = $currentMinute % $interval;
            if ($minutesAgo === 0) {
                $minutesAgo = $interval;
            }

            return time() - ($minutesAgo * 60);
        }

        if (is_numeric($minute) && is_numeric($hour)) {
            $currentMinute = (int) $now->format('i');
            $currentHour = (int) $now->format('H');

            if ($hour < $currentHour || ($hour === $currentHour && (int) $minute <= $currentMinute)) {
                return mktime((int) $hour, (int) $minute, 0, (int) $now->format('m'), (int) $now->format('d') - 1, (int) $now->format('Y'));
            }

            return mktime((int) $hour, (int) $minute, 0, (int) $now->format('m'), (int) $now->format('d'));
        }

        return time();
    }

    /**
     * Get the next scheduled time for a cron expression.
     */
    protected function getNextScheduledTime(string $expression, \DateTime $now, ?\DateTimeZone $tz): ?int
    {
        try {
            // Check if dragonmantank/cron-expression is available (v3.x API)
            if (class_exists('Cron\CronExpression') && method_exists('Cron\CronExpression', 'setTimezone')) {
                $cron = new \Cron\CronExpression($expression);
                $cron->setTimezone($tz ?? new \DateTimeZone('UTC'));

                return $cron->getNextRunDate($now)->getTimestamp();
            }

            return $this->parseNextRunSimple($expression, $now);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Simple cron next-run parser for basic expressions.
     */
    protected function parseNextRunSimple(string $expression, \DateTime $now): ?int
    {
        $parts = explode(' ', $expression);

        if (count($parts) < 5) {
            return null;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

        // Every minute
        if ($expression === '* * * * *') {
            return time() + 60;
        }

        // Every N minutes
        if (str_starts_with($minute, '*/')) {
            $interval = (int) substr($minute, 2);
            $currentMinute = (int) $now->format('i');
            $nextMinute = (ceil($currentMinute / $interval) * $interval) % 60;

            if ($nextMinute <= $currentMinute) {
                return mktime((int) $now->format('H') + 1, $nextMinute, 0, (int) $now->format('m'), (int) $now->format('d'));
            }

            return mktime((int) $now->format('H'), $nextMinute, 0, (int) $now->format('m'), (int) $now->format('d'));
        }

        // Daily at specific time
        if (is_numeric($minute) && is_numeric($hour) && $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
            $scheduledToday = mktime((int) $hour, (int) $minute, 0, (int) $now->format('m'), (int) $now->format('d'));

            if ($scheduledToday > time()) {
                return $scheduledToday;
            }

            return mktime((int) $hour, (int) $minute, 0, (int) $now->format('m'), (int) $now->format('d') + 1);
        }

        return null;
    }

    /**
     * Handle ScheduledTaskStarting event.
     */
    protected function handleScheduledTaskStarting(ScheduledTaskStarting $event): void
    {
        /** @var Event $task */
        $task = $event->task;
        $taskName = $this->getTaskNameFromEvent($task);

        if ($this->shouldIgnore($taskName)) {
            return;
        }

        $taskInfo = $this->extractTaskInfoFromEvent($task);
        $expectedAt = $this->calculateExpectedRunTime($task);
        $executionUuid = 'sched_' . Str::random(16);
        $startedAtNs = hrtime(true);

        $this->runningTasks[$executionUuid] = array_merge($taskInfo, [
            'expected_at' => $expectedAt,
            'started_at_ns' => $startedAtNs,
            'execution_uuid' => $executionUuid,
        ]);

        $nextRunAt = $this->calculateNextRunTime($task);

        $this->knownTasks[$taskName] = array_merge($taskInfo, [
            'next_run_at' => $nextRunAt,
        ]);

        // Add next_run_at to taskInfo for telemetry
        $taskInfoWithNext = array_merge($taskInfo, ['next_run_at' => $nextRunAt]);
        $this->sendTaskTelemetry($taskInfoWithNext);
        $this->sendExecutionStarted($executionUuid, $taskInfo, $expectedAt, $startedAtNs);
    }

    /**
     * Handle ScheduledTaskFinished event.
     */
    protected function handleScheduledTaskFinished(ScheduledTaskFinished $event): void
    {
        /** @var Event $task */
        $task = $event->task;
        $taskName = $this->getTaskNameFromEvent($task);

        if ($this->shouldIgnore($taskName)) {
            return;
        }

        $taskInfo = $this->extractTaskInfoFromEvent($task);
        $finishedAtNs = hrtime(true);

        // Find matching running execution by task name
        $executionUuid = null;
        $runningData = null;
        foreach ($this->runningTasks as $uuid => $data) {
            if (($data['task_name'] ?? '') === $taskName) {
                $executionUuid = $uuid;
                $runningData = $data;
                unset($this->runningTasks[$uuid]);
                break;
            }
        }

        if ($executionUuid === null) {
            $executionUuid = 'sched_' . Str::random(16);
            $runningData = [
                'expected_at' => $this->calculateExpectedRunTime($task),
                'started_at_ns' => $finishedAtNs,
            ];
        }

        $durationMs = (int) (($finishedAtNs - $runningData['started_at_ns']) / 1_000_000);
        $exitCode = $event->task->exitCode ?? 0;
        $commandUuid = $runningData['command_uuid'] ?? null;

        $exceptionClass = null;
        $exceptionMessage = null;
        if ($exitCode !== null && $exitCode !== 0) {
            // Get output from the event (Event has a public $output property)
            $output = property_exists($event->task, 'output') ? $event->task->output : null;
            if ($output && $output !== '/dev/null') {
                $exceptionMessage = trim((string) $output);
                // Extract exception class from output - look for a line with "Exception" as a standalone word
                if (preg_match('/^\s*(\w+Exception)\s*$/m', $output, $matches)) {
                    $exceptionClass = $matches[1];
                } elseif (preg_match('/(\w+Exception)/', $output, $matches)) {
                    // Fallback: exception class found anywhere in output
                    $exceptionClass = $matches[1];
                }
            }
        }

        $exceptionFile = null;
        $exceptionLine = null;
        $stackTrace = null;
        if ($exceptionMessage && $exceptionClass) {
            // Extract file and line from output like "at app/Console/Commands/FailingCommand.php:18"
            if (preg_match('/at\s+([^:]+):(\d+)/', $exceptionMessage, $matches)) {
                $exceptionFile = $matches[1];
                $exceptionLine = (int) $matches[2];
            }
            // Capture stack trace portion (after the exception class line)
            if (preg_match('/\n\s+\d+\s vendor frames\n/', $exceptionMessage)) {
                $stackTrace = $exceptionMessage;
            }
        }

        $executionData = [
            'execution_uuid' => $executionUuid,
            'task_name' => $taskName,
            'status' => ($exitCode === null || $exitCode === 0) ? 'completed' : 'failed',
            'expected_at' => $runningData['expected_at'],
            'started_at' => $this->hrtimeToUnix($runningData['started_at_ns']),
            'finished_at' => $this->hrtimeToUnix($finishedAtNs),
            'duration_ms' => $durationMs,
            'task_type' => $taskInfo['task_type'],
            'command_name' => $taskInfo['command_name'],
            'job_name' => $taskInfo['job_name'],
            'job_uuid' => $taskInfo['job_uuid'],
            'expression' => $taskInfo['expression'],
            'description' => $taskInfo['description'],
            'timezone' => $taskInfo['timezone'],
            'environment' => $taskInfo['environment'],
            'exception_class' => $exceptionClass,
            'exception_message' => $exceptionMessage,
            'exception_file' => $exceptionFile,
            'exception_line' => $exceptionLine,
            'stack_trace' => $stackTrace,
            'next_run_at' => $this->calculateNextRunTime($task),
        ];

        if ($commandUuid) {
            $executionData['command_uuid'] = $commandUuid;
        }

        $this->sendExecutionTelemetry($executionData);

        if (isset($this->knownTasks[$taskName])) {
            $this->knownTasks[$taskName]['next_run_at'] = $this->calculateNextRunTime($task);
            $this->knownTasks[$taskName]['last_status'] = $executionData['status'];
        }
    }

    /**
     * Handle ScheduledTaskSkipped event.
     */
    protected function handleScheduledTaskSkipped(ScheduledTaskSkipped $event): void
    {
        /** @var Event $task */
        $task = $event->task;
        $taskName = $this->getTaskNameFromEvent($task);

        if ($this->shouldIgnore($taskName)) {
            return;
        }

        // Skipped means the task's conditions weren't met (e.g., withoutOverlap, between times).
        // This is normal behavior, not a failure. Don't report as missed.
    }

    /**
     * Handle ScheduledTaskMissed event.
     */
    protected function handleScheduledTaskMissed(ScheduledTaskMissed $event): void
    {
        /** @var Event $task */
        $task = $event->task;
        $taskName = $this->getTaskNameFromEvent($task);

        if ($this->shouldIgnore($taskName)) {
            return;
        }

        $taskInfo = $this->extractTaskInfoFromEvent($task);

        $executionData = [
            'execution_uuid' => 'sched_' . Str::random(16),
            'task_name' => $taskName,
            'status' => 'missed',
            'expected_at' => $this->calculateExpectedRunTime($task),
            'started_at' => null,
            'finished_at' => null,
            'duration_ms' => null,
            'task_type' => $taskInfo['task_type'],
            'command_name' => $taskInfo['command_name'],
            'job_name' => $taskInfo['job_name'],
            'job_uuid' => $taskInfo['job_uuid'],
            'expression' => $taskInfo['expression'],
            'description' => $taskInfo['description'],
            'timezone' => $taskInfo['timezone'],
            'environment' => $taskInfo['environment'],
            'exception_class' => null,
            'exception_message' => null,
            'next_run_at' => $this->calculateNextRunTime($task),
        ];

        $this->sendExecutionTelemetry($executionData);
    }

    /**
     * Handle ScheduledTaskFailed event.
     */
    protected function handleScheduledTaskFailed(ScheduledTaskFailed $event): void
    {
        /** @var Event $task */
        $task = $event->task;
        $exception = $event->exception;
        $taskName = $this->getTaskNameFromEvent($task);

        if ($this->shouldIgnore($taskName)) {
            return;
        }

        $taskInfo = $this->extractTaskInfoFromEvent($task);

        // Find matching running execution by task name
        $executionUuid = null;
        $runningData = null;
        foreach ($this->runningTasks as $uuid => $data) {
            if (($data['task_name'] ?? '') === $taskName) {
                $executionUuid = $uuid;
                $runningData = $data;
                unset($this->runningTasks[$uuid]);
                break;
            }
        }

        if ($executionUuid === null) {
            $executionUuid = 'sched_' . Str::random(16);
            $runningData = [
                'expected_at' => $this->calculateExpectedRunTime($task),
                'started_at_ns' => hrtime(true),
            ];
        }

        $durationMs = (int) ((hrtime(true) - $runningData['started_at_ns']) / 1_000_000);
        $commandUuid = $runningData['command_uuid'] ?? null;

        // Extract exception details
        $exceptionClass = get_class($exception);
        $exceptionMessage = $exception->getMessage();
        $exceptionFile = $exception->getFile();
        $exceptionLine = $exception->getLine();

        // Get raw stack trace (backend will sanitize)
        $stackTrace = $exception->getTraceAsString();

        $executionData = [
            'execution_uuid' => $executionUuid,
            'task_name' => $taskName,
            'status' => 'failed',
            'expected_at' => $runningData['expected_at'],
            'started_at' => $this->hrtimeToUnix($runningData['started_at_ns']),
            'finished_at' => $this->hrtimeToUnix(hrtime(true)),
            'duration_ms' => max(0, $durationMs),
            'task_type' => $taskInfo['task_type'],
            'command_name' => $taskInfo['command_name'],
            'job_name' => $taskInfo['job_name'],
            'job_uuid' => $taskInfo['job_uuid'],
            'expression' => $taskInfo['expression'],
            'description' => $taskInfo['description'],
            'timezone' => $taskInfo['timezone'],
            'environment' => $taskInfo['environment'],
            'exception_class' => $exceptionClass,
            'exception_message' => $exceptionMessage,
            'exception_file' => $exceptionFile,
            'exception_line' => $exceptionLine,
            'stack_trace' => $stackTrace,
            'next_run_at' => $this->calculateNextRunTime($task),
        ];

        if ($commandUuid) {
            $executionData['command_uuid'] = $commandUuid;
        }

        $this->sendExecutionTelemetry($executionData);
    }

    /**
     * Send task telemetry to Watchtower.
     *
     * @param array<string, mixed> $taskInfo
     */
    protected function sendTaskTelemetry(array $taskInfo): void
    {
        $data = array_merge($taskInfo, [
            'next_run_at' => $taskInfo['next_run_at'] ?? null,
            'last_run_at' => null,
            'last_status' => null,
        ]);

        $payload = array_merge($data, [
            'environment' => $data['environment'] ?? config('watchtower.environment', 'production'),
            'agent_version' => config('watchtower.agent_version', '1.0.0'),
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'server_name' => gethostname(),
        ]);

        try {
            $this->client->sendSchedulerTaskTelemetry($payload, $this->timeout);
        } catch (Throwable $e) {
            if (config('watchtower.debug', false)) {
                logger()->debug('Watchtower scheduler task telemetry failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Convert hrtime(true) nanoseconds to Unix timestamp.
     */
    protected function hrtimeToUnix(int $hrtimeNs): int
    {
        return (int) (($hrtimeNs / 1_000_000_000) + $this->hrtimeOffset);
    }

    /**
     * Send execution started event (no-op, execution tracked internally).
     */
    protected function sendExecutionStarted(string $executionUuid, array $taskInfo, int $expectedAt, int $startedAtNs): void
    {
        // The started event is tracked in $runningTasks; actual telemetry
        // is sent when the execution finishes.
    }

    /**
     * Send execution telemetry to Watchtower.
     *
     * @param array<string, mixed> $executionData
     */
    protected function sendExecutionTelemetry(array $executionData): void
    {
        $payload = array_merge($executionData, [
            'environment' => $executionData['environment'] ?? config('watchtower.environment', 'production'),
            'agent_version' => config('watchtower.agent_version', '1.0.0'),
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'server_name' => gethostname(),
        ]);

        try {
            $this->client->sendSchedulerExecutionTelemetry($payload, $this->timeout);
        } catch (Throwable $e) {
            if (config('watchtower.debug', false)) {
                logger()->debug('Watchtower scheduler execution telemetry failed', [
                    'error' => $e->getMessage(),
                    'task' => $executionData['task_name'] ?? 'unknown',
                ]);
            }
        }
    }

    /**
     * Check if the given command UUID should be linked to a running scheduler task.
     */
    public function getSchedulerExecutionForCommand(string $commandName, string $commandUuid): ?string
    {
        foreach ($this->runningTasks as $executionUuid => $data) {
            if (($data['command_name'] ?? '') === $commandName) {
                $this->runningTasks[$executionUuid]['command_uuid'] = $commandUuid;

                return $executionUuid;
            }
        }

        return null;
    }

    /**
     * Get all known tasks.
     *
     * @return array<string, array>
     */
    public function getKnownTasks(): array
    {
        return $this->knownTasks;
    }

    /**
     * Get tasks that may have been missed (called periodically).
     *
     * @return array<string>
     */
    public function getPotentiallyMissedTasks(): array
    {
        $missed = [];
        $now = time();
        $graceSeconds = $this->gracePeriodMinutes * 60;

        foreach ($this->knownTasks as $taskName => $taskData) {
            if (! isset($taskData['next_run_at'])) {
                continue;
            }

            $nextRun = $taskData['next_run_at'];

            if ($nextRun + $graceSeconds < $now) {
                $hasRecentExecution = false;
                foreach ($this->runningTasks as $data) {
                    if (($data['task_name'] ?? '') === $taskName) {
                        $hasRecentExecution = true;
                        break;
                    }
                }

                if (! $hasRecentExecution && ($taskData['last_status'] ?? null) !== 'missed') {
                    $missed[] = $taskName;
                }
            }
        }

        return $missed;
    }
}
