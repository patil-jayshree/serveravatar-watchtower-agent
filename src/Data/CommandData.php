<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Data;

use Carbon\Carbon;

class CommandData
{
    public function __construct(
        public readonly string $commandUuid,
        public readonly string $commandName,
        public readonly string $status, // started | completed | failed
        public readonly ?int $exitCode,
        public readonly ?int $durationMs,
        public readonly ?int $startedAt,
        public readonly ?int $finishedAt,
        public readonly ?string $requestId,
        public readonly ?string $exceptionClass,
        public readonly ?string $exceptionMessage,
        public readonly ?string $exceptionFile,
        public readonly ?int $exceptionLine,
        public readonly ?string $stackTrace,
        public readonly array $arguments, // sanitized
        public readonly array $options, // sanitized
        public readonly string $environment,
        public readonly string $agentVersion,
        public readonly string $laravelVersion,
        public readonly string $phpVersion,
        public readonly ?string $serverName,
    ) {}

    /**
     * Create from array (e.g., from JSON decode).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            commandUuid: $data['command_uuid'] ?? '',
            commandName: $data['command_name'] ?? '',
            status: $data['status'] ?? 'started',
            exitCode: isset($data['exit_code']) ? (int) $data['exit_code'] : null,
            durationMs: isset($data['duration_ms']) ? (int) $data['duration_ms'] : null,
            startedAt: isset($data['started_at']) ? (int) $data['started_at'] : null,
            finishedAt: isset($data['finished_at']) ? (int) $data['finished_at'] : null,
            requestId: $data['request_id'] ?? null,
            exceptionClass: $data['exception_class'] ?? null,
            exceptionMessage: $data['exception_message'] ?? null,
            exceptionFile: $data['exception_file'] ?? null,
            exceptionLine: isset($data['exception_line']) ? (int) $data['exception_line'] : null,
            stackTrace: $data['stack_trace'] ?? null,
            arguments: $data['arguments'] ?? [],
            options: $data['options'] ?? [],
            environment: $data['environment'] ?? 'unknown',
            agentVersion: $data['agent_version'] ?? 'unknown',
            laravelVersion: $data['laravel_version'] ?? 'unknown',
            phpVersion: $data['php_version'] ?? PHP_VERSION,
            serverName: $data['server_name'] ?? null,
        );
    }

    /**
     * Convert to array for transmission.
     */
    public function toArray(): array
    {
        return [
            'command_uuid' => $this->commandUuid,
            'command_name' => $this->commandName,
            'status' => $this->status,
            'exit_code' => $this->exitCode,
            'duration_ms' => $this->durationMs,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'request_id' => $this->requestId,
            'exception_class' => $this->exceptionClass,
            'exception_message' => $this->exceptionMessage,
            'exception_file' => $this->exceptionFile,
            'exception_line' => $this->exceptionLine,
            'stack_trace' => $this->stackTrace,
            'arguments' => $this->arguments,
            'options' => $this->options,
            'environment' => $this->environment,
            'agent_version' => $this->agentVersion,
            'laravel_version' => $this->laravelVersion,
            'php_version' => $this->phpVersion,
            'server_name' => $this->serverName,
        ];
    }
}
