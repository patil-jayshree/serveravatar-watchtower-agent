<?php

namespace ServerAvatar\Watchtower\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ServerAvatar\Watchtower\Contracts\WatchtowerClientInterface;
use Symfony\Component\HttpFoundation\Response;

class RequestTelemetry
{
    protected ?string $requestId = null;
    protected ?float $startTime = null;

    /**
     * Store validation errors from a caught ValidationException so end() can read them.
     * Keyed by request ID to support concurrent requests.
     *
     * @var array<string, array<string, array<string>>>
     */
    protected static array $validationErrors = [];

    public function __construct(
        protected RequestSanitizer $sanitizer,
        protected WatchtowerClientInterface $client,
    ) {}

    /**
     * Start capturing a request.
     */
    public function start(Request $request): void
    {
        if (! config('watchtower.enabled', true) || ! config('watchtower.request_telemetry.enabled', true)) {
            return;
        }

        $this->requestId = $this->generateRequestId($request);
        $this->startTime = microtime(true);
    }

    /**
     * End capturing and send telemetry.
     */
    public function end(Request $request, Response $response): void
    {
        if (! config('watchtower.enabled', true) || ! $this->startTime) {
            return;
        }

        $durationMs = (int) round((microtime(true) - $this->startTime) * 1000);
        $memoryBytes = memory_get_usage(true);

        $route = $request->route();
        $routeName = $route ? $route->getName() : null;
        $controllerAction = $this->getControllerAction($route);

        // Check for stored validation errors first (for ValidationException caught by middleware)
        $storedRequestId = $this->requestId ?? $this->generateRequestId($request);
        $validationErrors = self::getAndClearValidationErrors($storedRequestId);

        // Determine response body, error type, and error message
        $responseBody = null;
        $errorType = null;
        $errorMessage = null;

        if ($validationErrors !== null) {
            // ValidationException was caught by WatchtowerExceptionHandler
            // Format as: "field: error\nfield: error"
            $errorType = 'ValidationException';
            $errorMessage = $this->formatValidationErrors($validationErrors);
            $responseBody = json_encode([
                'message' => 'Validation failed',
                'errors' => $validationErrors,
            ]);
        } elseif ($response->getStatusCode() >= 400) {
            // Non-2xx response — extract details from response body
            $responseBody = $this->extractResponseBody($response);
            $errorType = $this->deriveErrorType($response, $responseBody);
            $errorMessage = $this->extractErrorMessage($responseBody, $errorType);
        }

        $data = new \ServerAvatar\Watchtower\Data\RequestData(
            requestId: $this->requestId ?? $this->generateRequestId($request),
            method: $request->method(),
            path: '/' . ltrim($request->path(), '/'),
            url: $request->fullUrl(),
            routeName: $routeName,
            controllerAction: $controllerAction,
            statusCode: $response->getStatusCode(),
            durationMs: $durationMs,
            memoryBytes: $memoryBytes,
            host: $request->getHost(),
            userAgent: $request->userAgent(),
            ip: $request->ip(),
            environment: config('watchtower.environment', 'production'),
            contentType: $request->header('Content-Type', 'application/octet-stream'),
            requestedAt: now()->toIso8601String(),
            responseBody: $responseBody,
            errorType: $errorType,
            errorMessage: $errorMessage,
        );

        // Send non-blocking
        $this->sendTelemetry($data->toArray());
    }

    /**
     * Extract a readable response body (max 512KB, stripped of excess whitespace).
     */
    protected function extractResponseBody(Response $response): ?string
    {
        $content = $response->getContent();

        if (empty($content)) {
            return null;
        }

        // Truncate to 512KB
        if (strlen($content) > 512 * 1024) {
            $content = substr($content, 0, 512 * 1024) . "\n[Truncated — response too large]";
        }

        // Attempt to reformat JSON for readability
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $content = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return trim($content);
    }

    /**
     * Derive a short error type label from status code and response content.
     */
    protected function deriveErrorType(Response $response, ?string $body): ?string
    {
        $status = $response->getStatusCode();

        // Explicit type from JSON body
        if ($body) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && ! empty($decoded)) {
                // Laravel validation exception format
                if (isset($decoded['errors']) && isset($decoded['message'])) {
                    return 'ValidationException';
                }
                // Generic API error format
                if (isset($decoded['error']) || isset($decoded['error_description'])) {
                    return 'OAuthException';
                }
            }
        }

        // Derive from status code
        return match ($status) {
            400 => 'BadRequest',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'NotFound',
            419 => 'TokenMismatch',
            422 => 'UnprocessableEntity',
            429 => 'TooManyRequests',
            500 => 'InternalServerError',
            502 => 'BadGateway',
            503 => 'ServiceUnavailable',
            default => $status >= 500 ? 'ServerError' : 'ClientError',
        };
    }

    /**
     * Extract a human-readable error message from the response body.
     */
    protected function extractErrorMessage(?string $body, ?string $errorType): ?string
    {
        if (empty($body)) {
            return null;
        }

        // Strip HTML tags
        $text = strip_tags($body);

        // Handle JSON body
        if (str_starts_with(trim($text), '{') || str_starts_with(trim($text), '[')) {
            $decoded = json_decode($text, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // Laravel ValidationException JSON
                if (isset($decoded['message']) && is_string($decoded['message'])) {
                    return $decoded['message'];
                }
                // Laravel JSON API validation errors
                if (isset($decoded['errors'])) {
                    $lines = [];
                    foreach ((array) $decoded['errors'] as $field => $msgs) {
                        foreach ((array) $msgs as $msg) {
                            $lines[] = "{$field}: {$msg}";
                        }
                    }
                    return implode("\n", $lines);
                }
                // Generic error
                if (isset($decoded['error'])) {
                    return is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error']);
                }
                if (isset($decoded['error_description'])) {
                    return $decoded['error_description'];
                }
            }
        }

        // Plain text — truncate to 1KB
        $text = trim($text);
        if (strlen($text) > 1024) {
            $text = substr($text, 0, 1024) . '...';
        }

        return $text ?: null;
    }

    /**
     * Store validation errors from ValidationException.
     *
     * @param  array<string, array<string>>  $errors
     */
    public static function setValidationErrors(string $requestId, array $errors): void
    {
        self::$validationErrors[$requestId] = $errors;
    }

    /**
     * Get and clear validation errors for a request ID.
     *
     * @return array<string, array<string>>|null
     */
    public static function getAndClearValidationErrors(string $requestId): ?array
    {
        if (! isset(self::$validationErrors[$requestId])) {
            return null;
        }

        $errors = self::$validationErrors[$requestId];
        unset(self::$validationErrors[$requestId]);

        return $errors;
    }

    /**
     * Format validation errors as "field: error" lines.
     *
     * @param  array<string, array<string>>  $errors
     */
    protected function formatValidationErrors(array $errors): string
    {
        $lines = [];
        foreach ($errors as $field => $fieldErrors) {
            foreach ((array) $fieldErrors as $error) {
                $lines[] = "{$field}: {$error}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Generate or propagate a request ID.
     */
    protected function generateRequestId(Request $request): string
    {
        // Check for existing trace ID
        $traceId = $request->header('X-Trace-ID') ?? $request->header('X-Request-ID');

        if ($traceId) {
            return 'req_' . $traceId;
        }

        return 'req_' . Str::random(21);
    }

    /**
     * Get controller@action string from route.
     */
    protected function getControllerAction(?\Illuminate\Routing\Route $route): ?string
    {
        if (! $route) {
            return null;
        }

        $action = $route->getAction();

        if (isset($action['controller'])) {
            return $action['controller'];
        }

        if (isset($action['uses'])) {
            $uses = $action['uses'];
            if (is_string($uses)) {
                return $uses;
            }
        }

        return null;
    }

    /**
     * Get the current request ID.
     */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Send telemetry with graceful failure.
     */
    protected function sendTelemetry(array $data): void
    {
        try {
            $this->client->sendRequestTelemetry($data);
        } catch (\Throwable $e) {
            // Fail silently - do not interrupt the application
            // Log in debug mode only
            if (config('watchtower.debug', false)) {
                logger()->debug('Watchtower telemetry failed: ' . $e->getMessage());
            }
        }
    }
}
