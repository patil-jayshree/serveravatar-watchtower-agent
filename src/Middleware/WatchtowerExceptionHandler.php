<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ServerAvatar\Watchtower\Services\ExceptionTelemetry;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WatchtowerExceptionHandler
{
    public function __construct(
        protected ExceptionTelemetry $exceptionTelemetry
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get or generate request ID for correlation
        $requestId = $this->getOrGenerateRequestId($request);

        // Set the request ID in the telemetry service for correlation
        $this->exceptionTelemetry->setCurrentRequestId($requestId);

        try {
            $response = $next($request);

            // If response is an error (4xx or 5xx), capture it as an exception
            if ($response->getStatusCode() >= 400 && config('watchtower.exceptions.capture_http_errors', false)) {
                $this->captureHttpError($request, $response, $requestId);
            }

            return $response;
        } catch (Throwable $e) {
            // Capture the exception
            $this->captureException($e, $request);

            // Re-throw so Laravel's normal exception handling continues
            throw $e;
        }
    }

    /**
     * Get existing request ID or generate a new one.
     */
    protected function getOrGenerateRequestId(Request $request): ?string
    {
        // Check for existing request ID in header (from RequestTelemetry middleware)
        $existingId = $request->header('X-Request-ID');

        if ($existingId) {
            return $existingId;
        }

        // Generate new ID if tracking is enabled
        if (config('watchtower.request_telemetry.enabled', true)) {
            return 'req_' . Str::random(20);
        }

        return null;
    }

    /**
     * Capture an exception.
     */
    protected function captureException(Throwable $exception, Request $request): void
    {
        // Skip if exceptions are disabled
        if (! config('watchtower.exceptions.enabled', true)) {
            return;
        }

        // Get status code from exception if available
        $statusCode = $this->getStatusCodeFromException($exception);

        // Capture and send telemetry (non-blocking)
        $this->exceptionTelemetry->capture($exception, $statusCode, $request);
    }

    /**
     * Capture an HTTP error response as an exception.
     */
    protected function captureHttpError(Request $request, Response $response, ?string $requestId): void
    {
        // Skip if HTTP error capture is disabled
        if (! config('watchtower.exceptions.capture_http_errors', false)) {
            return;
        }

        $statusCode = $response->getStatusCode();

        // Create a synthetic exception for HTTP errors
        $exceptionClass = $this->getExceptionClassForStatusCode($statusCode);
        $message = $this->getMessageForStatusCode($statusCode, $request);

        // Build exception data manually
        $exceptionData = new \ServerAvatar\Watchtower\Data\ExceptionData(
            exceptionType: $exceptionClass,
            message: $message,
            file: $request->path(),
            line: 0,
            stackTrace: "HTTP {$statusCode} - {$message}",
            requestId: $requestId,
            statusCode: $statusCode,
            method: $request->method(),
            path: '/' . ltrim($request->path(), '/'),
            routeName: $request->route()?->getName(),
            controllerAction: $this->getControllerAction($request),
            host: $request->getHost(),
            userAgent: $request->userAgent(),
            environment: config('watchtower.environment', 'production'),
            laravelVersion: config('watchtower.app_version') ?? app()->version(),
            phpVersion: PHP_VERSION,
            agentVersion: config('watchtower.agent_version', '1.0.0'),
            occurredAt: now()->toIso8601String(),
        );

        // Sanitize and send
        $sanitizer = new \ServerAvatar\Watchtower\Services\ExceptionSanitizer();
        $data = $sanitizer->sanitize($exceptionData->toArray());

        $this->exceptionTelemetry->sendTelemetry($data);
    }

    /**
     * Get status code from exception if possible.
     */
    protected function getStatusCodeFromException(Throwable $exception): ?int
    {
        if (method_exists($exception, 'getStatusCode')) {
            try {
                return $exception->getStatusCode();
            } catch (\Throwable) {
                // Ignore
            }
        }

        return null;
    }

    /**
     * Get exception class name for HTTP status code.
     */
    protected function getExceptionClassForStatusCode(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Symfony\\Component\\HttpKernel\\Exception\\BadRequestHttpException',
            401 => 'Symfony\\Component\\HttpKernel\\Exception\\UnauthorizedHttpException',
            403 => 'Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException',
            404 => 'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException',
            405 => 'Symfony\\Component\\HttpKernel\\Exception\\MethodNotAllowedHttpException',
            419 => 'Illuminate\\Session\\TokenMismatchException',
            422 => 'Symfony\\Component\\HttpKernel\\Exception\\UnprocessableEntityHttpException',
            429 => 'Symfony\\Component\\HttpKernel\\Exception\\TooManyRequestsHttpException',
            500 => 'Symfony\\Component\\HttpKernel\\Exception\\InternalServerErrorHttpException',
            502 => 'Symfony\\Component\\HttpKernel\\Exception\\BadGatewayHttpException',
            503 => 'Symfony\\Component\\HttpKernel\\Exception\\ServiceUnavailableHttpException',
            default => 'Symfony\\Component\\HttpKernel\\Exception\\HttpException',
        };
    }

    /**
     * Get message for HTTP status code.
     */
    protected function getMessageForStatusCode(int $statusCode, Request $request): string
    {
        return match ($statusCode) {
            400 => 'Bad Request: ' . $request->method() . ' ' . $request->path(),
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found: ' . $request->method() . ' ' . $request->path(),
            405 => 'Method Not Allowed',
            419 => 'CSRF Token Mismatch',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => "HTTP Error {$statusCode}",
        };
    }

    /**
     * Get controller action from request.
     */
    protected function getControllerAction(Request $request): ?string
    {
        try {
            $action = $request->route()?->getAction();

            if (isset($action['controller'])) {
                return $action['controller'];
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}
