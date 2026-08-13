<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Data;

use ServerAvatar\Watchtower\Services\ExceptionSanitizer;
use Throwable;

class ExceptionData
{
    public function __construct(
        public readonly string $exceptionType,
        public readonly string $message,
        public readonly string $file,
        public readonly int $line,
        public readonly string|array $stackTrace,
        public readonly ?string $class = null,
        public readonly ?string $function = null,
        public readonly ?string $sourceFile = null,
        public readonly ?string $sourceContext = null,
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
        $sanitizer = new ExceptionSanitizer();

        // Parse the stack trace into structured format
        $parsedTrace = $sanitizer->parseTrace($exception);

        // Sanitize the trace
        $sanitizedTrace = $sanitizer->sanitizeTrace($parsedTrace);

        // Extract exception context (class + function where exception was thrown)
        $context = $sanitizer->extractExceptionContext($exception);

        // Extract source code context around the exception line
        $sourceContext = $sanitizer->extractSourceContext(
            $exception->getFile(),
            $exception->getLine()
        );

        return new self(
            exceptionType: get_class($exception),
            message: $exception->getMessage(),
            file: $exception->getFile(),
            line: $exception->getLine(),
            stackTrace: $sanitizedTrace, // Structured array
            class: $context['class'],
            function: $context['function'],
            sourceFile: $sourceContext['file'] ?? null,
            sourceContext: $sourceContext['context'] ?? null,
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
            'stack_trace' => is_array($this->stackTrace) ? json_encode($this->stackTrace) : $this->stackTrace,
            'class' => $this->class,
            'function' => $this->function,
            'source_file' => $this->sourceFile,
            'source_context' => $this->sourceContext,
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
