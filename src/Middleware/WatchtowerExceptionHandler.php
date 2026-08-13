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
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Use the request ID from the RequestTelemetry singleton if already set,
        // otherwise generate one. This ensures the exception handler and the
        // WatchtowerRequestTelemetry middleware use the SAME request ID.
        $requestId = $this->requestTelemetry->getRequestId() ?? $this->getOrGenerateRequestId($request);

        // Set the request ID in the telemetry service for correlation
        $this->exceptionTelemetry->setCurrentRequestId($requestId);

        $response = null;

        try {
            $response = $next($request);

            // If response is an error (4xx or 5xx), capture it as an exception
            if ($response->getStatusCode() >= 400 && config('watchtower.exceptions.capture_http_errors', false)) {
                $this->captureHttpError($request, $response, $requestId);
            }

            return $response;
        } catch (Throwable $e) {
            // Store validation errors BEFORE re-throwing
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                $errors = $e->errors();
                $reqId = $this->exceptionTelemetry->getCurrentRequestId() ?? $requestId;
                $this->requestTelemetry->setValidationErrors($reqId, $errors);

                // Format validation errors and inject into exception message via reflection
                // so the Related Exceptions card (if ever shown) has meaningful data
                $formatted = $this->formatValidationErrors($errors);
                if (! empty($formatted)) {
                    $refl = new \ReflectionClass(\Illuminate\Validation\ValidationException::class);
                    $msgProp = $refl->getProperty('message');
                    $msgProp->setAccessible(true);
                    $msgProp->setValue($e, $formatted);
                }
            }

            // IMPORTANT: call end() with a constructed response BEFORE re-throwing.
            // This ensures the RequestEvent is recorded even when an exception occurs.
            if ($response === null) {
                $response = $this->createResponseFromException($e);
            }

            // Call end() using the same RequestTelemetry singleton used by middleware
            try {
                $this->requestTelemetry->end($request, $response);
            } catch (\Throwable $ignored) {
                // Never let telemetry errors affect the application
            }

            // Re-throw so Laravel's normal exception handling continues
            throw $e;
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
            return 'req_' . Str::random(20);
        }
        return null;
    }

    /**
     * Capture an exception.
     */
    protected function captureException(Throwable $exception, Request $request): void
    {
        if (! config('watchtower.exceptions.enabled', true)) {
            return;
        }

        $statusCode = $this->getStatusCodeFromException($exception);

        if ($exception instanceof \Illuminate\Validation\ValidationException) {
            $requestId = $this->exceptionTelemetry->getCurrentRequestId()
                ?? 'req_' . Str::random(20);
            $errors = $exception->errors();
            RequestTelemetry::setValidationErrors($requestId, $errors);

            $formatted = $this->formatValidationErrors($errors);
            if (! empty($formatted)) {
                $refl = new \ReflectionClass(\Illuminate\Validation\ValidationException::class);
                $msgProp = $refl->getProperty('message');
                $msgProp->setAccessible(true);
                $msgProp->setValue($exception, $formatted);
            }

            return;
        }

        $this->exceptionTelemetry->capture($exception, $statusCode, $request);
    }

    /**
     * Capture an HTTP error response as an exception.
     */
    protected function captureHttpError(Request $request, Response $response, ?string $requestId): void
    {
        if (! config('watchtower.exceptions.capture_http_errors', false)) {
            return;
        }

        $statusCode = $response->getStatusCode();
        $exceptionClass = $this->getExceptionClassForStatusCode($statusCode);
        $message = $this->getMessageForStatusCode($statusCode, $request);

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

        $sanitizer = new \ServerAvatar\Watchtower\Services\ExceptionSanitizer();
        $data = $sanitizer->sanitize($exceptionData->toArray());

        $this->exceptionTelemetry->sendTelemetry($data);
    }

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

    protected function getExceptionClassForStatusCode(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Symfony\Component\HttpKernel\Exception\BadRequestHttpException',
            401 => 'Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException',
            403 => 'Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException',
            404 => 'Symfony\Component\HttpKernel\Exception\NotFoundHttpException',
            405 => 'Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException',
            419 => 'Illuminate\Session\TokenMismatchException',
            422 => 'Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException',
            429 => 'Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException',
            500 => 'Symfony\Component\HttpKernel\Exception\InternalServerErrorHttpException',
            502 => 'Symfony\Component\HttpKernel\Exception\BadGatewayHttpException',
            503 => 'Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException',
            default => 'Symfony\Component\HttpKernel\Exception\HttpException',
        };
    }

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

    /**
     * Format validation errors from ValidationException->errors() into a readable string.
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
