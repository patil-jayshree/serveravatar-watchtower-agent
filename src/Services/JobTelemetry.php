<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Services;

use Illuminate\Queue\Events\JobException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Str;
use ServerAvatar\Watchtower\Contracts\WatchtowerClientInterface;
use ServerAvatar\Watchtower\Data\JobData;

class JobTelemetry
{
    /**
     * Whether job monitoring is enabled.
     */
    protected bool $enabled = false;

    /**
     * Current request/trace ID context.
     */
    protected ?string $currentRequestId = null;

    /**
     * Current trace ID (from distributed tracing).
     */
    protected ?string $currentTraceId = null;

    /**
     * Job start times for duration calculation.
     */
    protected array $jobStartTimes = [];

    /**
     * Whether listeners are registered.
     */
    protected bool $listenersRegistered = false;

    public function __construct(
        protected JobSanitizer $sanitizer,
        protected WatchtowerClientInterface $client,
    ) {}

    /**
     * Enable job monitoring.
     */
    public function enable(): void
    {
        if (! config('watchtower.enabled', true) || ! config('watchtower.queue_monitoring.enabled', false)) {
            return;
        }

        $this->enabled = true;
        $this->registerListeners();
    }

    /**
     * Disable job monitoring.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Set the current request ID context.
     */
    public function setCurrentRequestId(?string $requestId): void
    {
        $this->currentRequestId = $requestId;
    }

    /**
     * Set the current trace ID (distributed tracing).
     */
    public function setCurrentTraceId(?string $traceId): void
    {
        $this->currentTraceId = $traceId;
    }

    /**
     * Get the current request ID.
     */
    public function getCurrentRequestId(): ?string
    {
        return $this->currentRequestId;
    }

    /**
     * Get the current trace ID.
     */
    public function getCurrentTraceId(): ?string
    {
        return $this->currentTraceId;
    }

    /**
     * Register queue event listeners.
     */
    protected function registerListeners(): void
    {
        if ($this->listenersRegistered) {
            return;
        }

        $self = $this;

        // JobQueued - when a job is pushed to the queue
        $this->appEvents()->listen('Illuminate\Queue\Events\JobQueued', function (JobQueued $event) use ($self) {
            if (! $self->enabled) {
                return;
            }

            $self->handleJobQueued($event);
        });

        // JobProcessing - when a worker starts processing a job
        $this->appEvents()->listen('Illuminate\Queue\Events\JobProcessing', function (JobProcessing $event) use ($self) {
            if (! $self->enabled) {
                return;
            }

            $self->handleJobProcessing($event);
        });

        // JobProcessed - when a job completes successfully
        $this->appEvents()->listen('Illuminate\Queue\Events\JobProcessed', function (JobProcessed $event) use ($self) {
            if (! $self->enabled) {
                return;
            }

            $self->handleJobProcessed($event);
        });

        // JobFailed - when a job fails
        $this->appEvents()->listen('Illuminate\Queue\Events\JobFailed', function (JobFailed $event) use ($self) {
            if (! $self->enabled) {
                return;
            }

            $self->handleJobFailed($event);
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
     * Handle JobQueued event.
     */
    protected function handleJobQueued(JobQueued $event): void
    {
        $jobName = $this->resolveJobName($event->job);
        $queue = $event->queue ?? 'default';
        $connection = $event->connectionName ?? 'sync';

        // Check if job should be ignored
        if ($this->sanitizer->shouldIgnore($jobName, $queue)) {
            return;
        }

        // Payload is a public string property for JobQueued
        $payload = $this->normalizePayload($event->payload);

        $jobId = $this->resolveJobId($event->job, $payload);
        $jobUuid = $this->generateJobUuid($payload);

        $data = new JobData(
            eventType: 'queued',
            jobId: $jobId,
            jobUuid: $jobUuid,
            jobName: $jobName,
            queue: $queue,
            connection: $connection,
            attempts: 0,
            durationMs: null,
            requestId: $this->resolveRequestId($event->job, $payload),
            traceId: $this->resolveTraceId($payload),
            queuedAt: (int) ($event->queuedAt?->timestamp ?? time()),
            startedAt: null,
            completedAt: null,
            failedAt: null,
            exceptionClass: null,
            exceptionMessage: null,
            exceptionFile: null,
            exceptionLine: null,
            stackTrace: null,
            environment: config('watchtower.environment'),
            agentVersion: config('watchtower.agent_version'),
            laravelVersion: app()->version(),
            phpVersion: PHP_VERSION,
            serverName: gethostname(),
        );

        $this->sendTelemetry($data->toArray());
    }

    /**
     * Handle JobProcessing event.
     */
    protected function handleJobProcessing(JobProcessing $event): void
    {
        $jobName = $this->resolveJobName($event->job);
        $queue = $event->job->getQueue() ?? 'default';
        $connection = $event->job->getConnectionName() ?? 'sync';

        // Check if job should be ignored
        if ($this->sanitizer->shouldIgnore($jobName, $queue)) {
            return;
        }

        // Get payload from job object (JobProcessing has no $payload property)
        $payload = $this->normalizePayload($event->job->payload());

        $jobId = $this->resolveJobId($event->job, $payload);
        $jobUuid = $this->resolveJobUuid($event->job, $payload);
        $attempts = $event->job->attempts() ?? 1;

        // Store start time for duration calculation
        $startKey = $this->getJobKey($jobId, $jobUuid);
        $this->jobStartTimes[$startKey] = microtime(true);

        $data = new JobData(
            eventType: 'started',
            jobId: $jobId,
            jobUuid: $jobUuid,
            jobName: $jobName,
            queue: $queue,
            connection: $connection,
            attempts: $attempts,
            durationMs: null,
            requestId: $this->resolveRequestId($event->job, $payload),
            traceId: $this->resolveTraceId($payload),
            queuedAt: null,
            startedAt: time(),
            completedAt: null,
            failedAt: null,
            exceptionClass: null,
            exceptionMessage: null,
            exceptionFile: null,
            exceptionLine: null,
            stackTrace: null,
            environment: config('watchtower.environment'),
            agentVersion: config('watchtower.agent_version'),
            laravelVersion: app()->version(),
            phpVersion: PHP_VERSION,
            serverName: gethostname(),
        );

        $this->sendTelemetry($data->toArray());
    }

    /**
     * Handle JobProcessed event.
     */
    protected function handleJobProcessed(JobProcessed $event): void
    {
        $jobName = $this->resolveJobName($event->job);
        $queue = $event->job->getQueue() ?? 'default';
        $connection = $event->job->getConnectionName() ?? 'sync';

        // Check if job should be ignored
        if ($this->sanitizer->shouldIgnore($jobName, $queue)) {
            return;
        }

        // Get payload from job object (JobProcessed has no $payload property)
        $payload = $this->normalizePayload($event->job->payload());

        $jobId = $this->resolveJobId($event->job, $payload);
        $jobUuid = $this->resolveJobUuid($event->job, $payload);
        $attempts = $event->job->attempts() ?? 1;

        // Calculate duration
        $startKey = $this->getJobKey($jobId, $jobUuid);
        $durationMs = null;
        $startedAt = null;

        if (isset($this->jobStartTimes[$startKey])) {
            $durationMs = (microtime(true) - $this->jobStartTimes[$startKey]) * 1000;
            $startedAt = (int) $this->jobStartTimes[$startKey];
            unset($this->jobStartTimes[$startKey]);
        }

        $data = new JobData(
            eventType: 'completed',
            jobId: $jobId,
            jobUuid: $jobUuid,
            jobName: $jobName,
            queue: $queue,
            connection: $connection,
            attempts: $attempts,
            durationMs: $durationMs,
            requestId: $this->resolveRequestId($event->job, $payload),
            traceId: $this->resolveTraceId($payload),
            queuedAt: null,
            startedAt: $startedAt,
            completedAt: time(),
            failedAt: null,
            exceptionClass: null,
            exceptionMessage: null,
            exceptionFile: null,
            exceptionLine: null,
            stackTrace: null,
            environment: config('watchtower.environment'),
            agentVersion: config('watchtower.agent_version'),
            laravelVersion: app()->version(),
            phpVersion: PHP_VERSION,
            serverName: gethostname(),
        );

        $this->sendTelemetry($data->toArray());
    }

    /**
     * Handle JobFailed event.
     */
    protected function handleJobFailed(JobFailed $event): void
    {
        $jobName = $this->resolveJobName($event->job);
        $queue = $event->job->getQueue() ?? 'default';
        $connection = $event->job->getConnectionName() ?? 'sync';
        $exception = $event->exception;

        // Check if job should be ignored
        if ($this->sanitizer->shouldIgnore($jobName, $queue)) {
            return;
        }

        // JobFailed event does not have $payload property; get from job object
        $payload = $this->normalizePayload($event->job->payload());

        $jobId = $this->resolveJobId($event->job, $payload);
        $jobUuid = $this->resolveJobUuid($event->job, $payload);
        $attempts = $event->job->attempts() ?? 1;

        // Calculate duration
        $startKey = $this->getJobKey($jobId, $jobUuid);
        $durationMs = null;
        $startedAt = null;

        if (isset($this->jobStartTimes[$startKey])) {
            $durationMs = (microtime(true) - $this->jobStartTimes[$startKey]) * 1000;
            $startedAt = (int) $this->jobStartTimes[$startKey];
            unset($this->jobStartTimes[$startKey]);
        }

        // Sanitize exception info
        $exceptionMessage = $this->sanitizer->sanitizeText($exception->getMessage());
        $stackTrace = $this->sanitizer->sanitizeStackTrace($exception->getTraceAsString());

        $data = new JobData(
            eventType: 'failed',
            jobId: $jobId,
            jobUuid: $jobUuid,
            jobName: $jobName,
            queue: $queue,
            connection: $connection,
            attempts: $attempts,
            durationMs: $durationMs,
            requestId: $this->resolveRequestId($event->job, $payload),
            traceId: $this->resolveTraceId($payload),
            queuedAt: null,
            startedAt: $startedAt,
            completedAt: null,
            failedAt: time(),
            exceptionClass: get_class($exception),
            exceptionMessage: $exceptionMessage,
            exceptionFile: $exception->getFile(),
            exceptionLine: $exception->getLine(),
            stackTrace: $stackTrace,
            environment: config('watchtower.environment'),
            agentVersion: config('watchtower.agent_version'),
            laravelVersion: app()->version(),
            phpVersion: PHP_VERSION,
            serverName: gethostname(),
        );

        $this->sendTelemetry($data->toArray());
    }

    /**
     * Normalize payload to an array.
     * JobQueued event may pass a JSON string while other events pass an array.
     */
    protected function normalizePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Resolve the job name from a job object.
     */
    protected function resolveJobName(mixed $job): string
    {
        // If job has a payload method, try to get displayName from it first
        // (This works for CallQueuedHandler, DatabaseJob, SyncJob, etc.)
        if (is_object($job) && method_exists($job, 'payload')) {
            try {
                $rawPayload = $job->payload();
                // payload() may return string (SyncJob) or array (DatabaseJob, etc.)
                $payload = is_string($rawPayload) ? json_decode($rawPayload, true) : $rawPayload;
                if (is_array($payload) && ! empty($payload['displayName'])) {
                    return $payload['displayName'];
                }
            } catch (\Throwable) {
                // Ignore payload errors
            }
        }

        // If this is a queue job wrapper (DatabaseJob, SyncJob, etc.),
        // try to unwrap the underlying job
        if (is_object($job) && method_exists($job, 'getJob')) {
            $realJob = $job->getJob();
            if ($realJob && is_object($realJob)) {
                $name = $this->resolveJobName($realJob);
                if ($name !== 'UnknownJob') {
                    return $name;
                }
            }
        }

        // Use displayName() if available (Laravel's preferred method)
        if (is_object($job) && method_exists($job, 'displayName')) {
            return $job->displayName();
        }

        // Fall back to class name
        if (is_object($job)) {
            return get_class($job);
        }

        return 'UnknownJob';
    }

    /**
     * Resolve the job ID from job/payload.
     */
    protected function resolveJobId(mixed $job, array $payload): ?string
    {
        // Laravel's queue job ID
        if (is_object($job) && method_exists($job, 'getJobId')) {
            $id = $job->getJobId();
            if ($id !== null) {
                return (string) $id;
            }
        }

        // From payload
        return isset($payload['id']) ? (string) $payload['id'] : null;
    }

    /**
     * Resolve or generate a job UUID.
     */
    protected function resolveJobUuid(mixed $job, array $payload): ?string
    {
        // Check payload for UUID
        if (isset($payload['uuid'])) {
            return $payload['uuid'];
        }

        // Check job for UUID property
        if (is_object($job) && property_exists($job, 'uuid')) {
            return $job->uuid ?? null;
        }

        // Check job for uniqueId (ShouldBeUnique jobs)
        if (is_object($job) && method_exists($job, 'uniqueId')) {
            return 'unique_' . $job->uniqueId();
        }

        return null;
    }

    /**
     * Generate a job UUID if not available.
     */
    protected function generateJobUuid(array $payload): ?string
    {
        if (isset($payload['uuid'])) {
            return $payload['uuid'];
        }

        return 'job_' . Str::random(16);
    }

    /**
     * Resolve request ID from job/payload.
     */
    protected function resolveRequestId(mixed $job, array $payload): ?string
    {
        // Check payload for request ID
        if (isset($payload['request_id'])) {
            return $payload['request_id'];
        }

        // Check for job with traceId property
        if (is_object($job) && property_exists($job, 'traceId')) {
            return $job->traceId ?? null;
        }

        // Use the current request ID from middleware context
        return $this->currentRequestId;
    }

    /**
     * Resolve trace ID from payload.
     */
    protected function resolveTraceId(array $payload): ?string
    {
        if (isset($payload['trace_id'])) {
            return $payload['trace_id'];
        }

        if (isset($payload['traceId'])) {
            return $payload['traceId'];
        }

        return $this->currentTraceId;
    }

    /**
     * Get a unique key for tracking job start times.
     */
    protected function getJobKey(?string $jobId, ?string $jobUuid): string
    {
        return $jobUuid ?? $jobId ?? Str::random(16);
    }

    /**
     * Send telemetry to Watchtower.
     */
    protected function sendTelemetry(array $data): void
    {
        // Sanitize the data
        $data = $this->sanitizer->sanitize($data);

        $timeout = (int) config('watchtower.queue_monitoring.timeout', 3);

        try {
            $this->client->sendJobTelemetry($data, $timeout);
        } catch (\Throwable $e) {
            // Never let telemetry errors affect job execution
            if (config('watchtower.debug', false)) {
                logger()->debug('Watchtower job telemetry failed', [
                    'error' => $e->getMessage(),
                    'job' => $data['job_name'] ?? 'unknown',
                ]);
            }
        }
    }
}
