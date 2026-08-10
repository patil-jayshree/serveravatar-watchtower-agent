<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Data;

use Throwable;

class ExceptionData
{
    public function __construct(
        public readonly string $exceptionType,
        public readonly string $message,
        public readonly string $file,
        public readonly int $line,
        public readonly string $stackTrace,
        public readonly ?string $requestId = null,
        public readonly ?int $statusCode = null,
        public readonly ?string $method = null,
        public readonly ?string $path = null,
        public readonly ?string $routeName = null,
        public readonly ?string $controllerAction = null,
        public readonly ?string $host = null,
        public readonly ?string $userAgent = null,
        public readonly string $environment = 'production',
        public readonly ?string $laravelVersion = null,
        public readonly ?string $phpVersion = null,
        public readonly ?string $agentVersion = null,
        public readonly string $occurredAt = '',
    ) {}

    /**
     * Create from a Throwable (exception).
     */
    public static function fromThrowable(
        Throwable $exception,
        ?string $requestId = null,
        ?int $statusCode = null,
        ?string $method = null,
        ?string $path = null,
        ?string $routeName = null,
        ?string $controllerAction = null
    ): self {
        return new self(
            exceptionType: get_class($exception),
            message: $exception->getMessage(),
            file: $exception->getFile(),
            line: $exception->getLine(),
            stackTrace: $exception->getTraceAsString(),
            requestId: $requestId,
            statusCode: $statusCode,
            method: $method,
            path: $path,
            routeName: $routeName,
            controllerAction: $controllerAction,
            environment: config('watchtower.environment', 'production'),
            laravelVersion: config('watchtower.app_version') ?? app()->version(),
            phpVersion: PHP_VERSION,
            agentVersion: config('watchtower.agent_version', '1.0.0'),
            occurredAt: now()->toIso8601String(),
        );
    }

    /**
     * Convert to array for API submission.
     */
    public function toArray(): array
    {
        return [
            'exception_type' => $this->exceptionType,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'stack_trace' => $this->stackTrace,
            'request_id' => $this->requestId,
            'status_code' => $this->statusCode,
            'method' => $this->method,
            'path' => $this->path,
            'route_name' => $this->routeName,
            'controller_action' => $this->controllerAction,
            'host' => $this->host ?? $this->detectHost(),
            'user_agent' => $this->userAgent ?? $this->detectUserAgent(),
            'environment' => $this->environment,
            'laravel_version' => $this->laravelVersion,
            'php_version' => $this->phpVersion,
            'agent_version' => $this->agentVersion,
            'occurred_at' => $this->occurredAt ?: now()->toIso8601String(),
        ];
    }

    /**
     * Detect the host from the request.
     */
    protected function detectHost(): ?string
    {
        if (app()->runningInConsole()) {
            return null;
        }

        return request()->getHost();
    }

    /**
     * Detect the user agent from the request.
     */
    protected function detectUserAgent(): ?string
    {
        if (app()->runningInConsole()) {
            return null;
        }

        return request()->userAgent();
    }
}
