<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Data;

class JobData
{
    public function __construct(
        public readonly string $eventType,
        public readonly ?string $jobId,
        public readonly ?string $jobUuid,
        public readonly string $jobName,
        public readonly ?string $queue,
        public readonly ?string $connection,
        public readonly int $attempts,
        public readonly ?float $durationMs,
        public readonly ?string $requestId,
        public readonly ?string $traceId,
        public readonly ?int $queuedAt,
        public readonly ?int $startedAt,
        public readonly ?int $completedAt,
        public readonly ?int $failedAt,
        public readonly ?string $exceptionClass,
        public readonly ?string $exceptionMessage,
        public readonly ?string $exceptionFile,
        public readonly ?int $exceptionLine,
        public readonly ?string $stackTrace,
        public readonly ?string $environment,
        public readonly ?string $agentVersion,
        public readonly ?string $laravelVersion,
        public readonly ?string $phpVersion,
        public readonly ?string $serverName,
    ) {}

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            eventType: $data['event_type'] ?? 'unknown',
            jobId: $data['job_id'] ?? null,
            jobUuid: $data['job_uuid'] ?? null,
            jobName: $data['job_name'] ?? 'UnknownJob',
            queue: $data['queue'] ?? null,
            connection: $data['connection'] ?? null,
            attempts: (int) ($data['attempts'] ?? 1),
            durationMs: isset($data['duration_ms']) ? (float) $data['duration_ms'] : null,
            requestId: $data['request_id'] ?? null,
            traceId: $data['trace_id'] ?? null,
            queuedAt: isset($data['queued_at']) ? (int) $data['queued_at'] : null,
            startedAt: isset($data['started_at']) ? (int) $data['started_at'] : null,
            completedAt: isset($data['completed_at']) ? (int) $data['completed_at'] : null,
            failedAt: isset($data['failed_at']) ? (int) $data['failed_at'] : null,
            exceptionClass: $data['exception_class'] ?? null,
            exceptionMessage: $data['exception_message'] ?? null,
            exceptionFile: $data['exception_file'] ?? null,
            exceptionLine: isset($data['exception_line']) ? (int) $data['exception_line'] : null,
            stackTrace: $data['stack_trace'] ?? null,
            environment: $data['environment'] ?? null,
            agentVersion: $data['agent_version'] ?? null,
            laravelVersion: $data['laravel_version'] ?? null,
            phpVersion: $data['php_version'] ?? null,
            serverName: $data['server_name'] ?? null,
        );
    }

    /**
     * Convert to array for transmission.
     */
    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType,
            'job_id' => $this->jobId,
            'job_uuid' => $this->jobUuid,
            'job_name' => $this->jobName,
            'queue' => $this->queue,
            'connection' => $this->connection,
            'attempts' => $this->attempts,
            'duration_ms' => $this->durationMs,
            'request_id' => $this->requestId,
            'trace_id' => $this->traceId,
            'queued_at' => $this->queuedAt,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'failed_at' => $this->failedAt,
            'exception_class' => $this->exceptionClass,
            'exception_message' => $this->exceptionMessage,
            'exception_file' => $this->exceptionFile,
            'exception_line' => $this->exceptionLine,
            'stack_trace' => $this->stackTrace,
            'environment' => $this->environment,
            'agent_version' => $this->agentVersion,
            'laravel_version' => $this->laravelVersion,
            'php_version' => $this->phpVersion,
            'server_name' => $this->serverName,
        ];
    }
}
