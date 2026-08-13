<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ServerAvatar\Watchtower\Services\ExceptionTelemetry;
use ServerAvatar\Watchtower\Services\RequestTelemetry;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WatchtowerExceptionHandler
{
    public function __construct(
        protected ExceptionTelemetry $exceptionTelemetry,
        protected RequestTelemetry $requestTelemetry
    ) {}

    /**
     * Handle an incoming request.
     *
     * Catches exceptions that propagate through the middleware stack
     * (e.g., from other middleware or when a controller re-throws).
     *
     * Exceptions caught by Laravel's internal exception handler are NOT
     * propagated — they are rendered into HTTP responses before this
     * middleware sees them. Those are captured in WatchtowerRequestTelemetry::terminate()
     * which reads the original exception from $response->exception.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestTelemetry->getRequestId() ?? $this->getOrGenerateRequestId($request);
        $this->exceptionTelemetry->setCurrentRequestId($requestId);

        try {
            $response = $next($request);

            // NOTE: Do NOT capture $response status codes here.
            // That is handled by WatchtowerRequestTelemetry::terminate()
            // which has access to the original exception object.
            return $response;

        } catch (Throwable $e) {
            $this->handleException($e, $request);
            throw $e;
        }
    }

    /**
     * Handle an exception: store validation errors and capture telemetry.
     */
    protected function handleException(Throwable $e, Request $request): void
    {
        // Store validation errors if applicable
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            $reqId = $this->exceptionTelemetry->getCurrentRequestId();
            $this->requestTelemetry->setValidationErrors($reqId ?? 'req_' . Str::random(21), $e->errors());

            $formatted = $this->formatValidationErrors($e->errors());
            if (! empty($formatted)) {
                $refl = new \ReflectionClass(\Illuminate\Validation\ValidationException::class);
                $msgProp = $refl->getProperty('message');
                $msgProp->setAccessible(true);
                $msgProp->setValue($e, $formatted);
            }
        }

        // Capture the exception
        $statusCode = $this->getStatusCodeFromException($e);
        try {
            $this->exceptionTelemetry->capture($e, $statusCode, $request);
        } catch (\Throwable $ignored) {
        }

        // Also call end() so the request event is recorded
        $response = $this->createResponseFromException($e);
        try {
            $this->requestTelemetry->end($request, $response);
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * Get existing request ID or generate a new one.
     */
    protected function getOrGenerateRequestId(Request $request): ?string
    {
        $existingId = $request->header('X-Request-ID');
        if ($existingId) {
            return $existingId;
        }
        if (config('watchtower.request_telemetry.enabled', true)) {
            return 'req_' . Str::random(21);
        }
        return null;
    }

    /**
     * Get status code from an exception.
     */
    protected function getStatusCodeFromException(Throwable $exception): ?int
    {
        if (method_exists($exception, 'getStatusCode')) {
            try {
                return $exception->getStatusCode();
            } catch (\Throwable) {
            }
        }
        return null;
    }

    /**
     * Format validation errors into "field: error" lines.
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
     * Create a Response object from an exception.
     */
    protected function createResponseFromException(Throwable $e): Response
    {
        $statusCode = 500;
        $content = '';

        if (method_exists($e, 'getStatusCode')) {
            try {
                $statusCode = $e->getStatusCode();
            } catch (\Throwable) {
            }
        }

        if ($e instanceof \Illuminate\Validation\ValidationException) {
            $errors = $e->errors();
            if (! empty($errors)) {
                $content = json_encode([
                    'message' => $e->getMessage(),
                    'errors' => $errors,
                ]);
            }
        }

        return new Response($content, $statusCode);
    }
}
