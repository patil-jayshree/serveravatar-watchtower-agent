<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Middleware;

use Closure;
use Illuminate\Http\Request;
use ServerAvatar\Watchtower\Services\ExceptionTelemetry;
use ServerAvatar\Watchtower\Services\RequestTelemetry;
use Symfony\Component\HttpFoundation\Response;

class WatchtowerRequestTelemetry
{
    protected $requestIdCallback = null;

    public function __construct(
        protected RequestTelemetry $telemetry,
        protected ExceptionTelemetry $exceptionTelemetry
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('watchtower.enabled', true) || ! config('watchtower.request_telemetry.enabled', true)) {
            return $next($request);
        }

        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        $this->telemetry->start($request);

        if ($this->requestIdCallback) {
            ($this->requestIdCallback)($this->telemetry->getRequestId());
        }

        $this->exceptionTelemetry->setCurrentRequestId($this->telemetry->getRequestId());

        $response = $next($request);

        $this->telemetry->end($request, $response);

        return $response;
    }

    /**
     * Handle post-response processing.
     *
     * In Laravel 11+, when an exception is caught by Laravel's internal exception
     * handler and rendered into an HTTP response, the original exception object
     * is attached to the response as $response->exception.
     *
     * This terminate() hook captures the original exception with its real type
     * and message — BEFORE Laravel's renderer strips it to a generic error.
     *
     * Note: Exceptions that propagate through the middleware stack (bubble up
     * via "throw $e") are caught by WatchtowerExceptionHandler::handle() instead.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! config('watchtower.enabled', true) || ! config('watchtower.exceptions.enabled', true)) {
            return;
        }

        if (! config('watchtower.exceptions.capture_http_errors', false)) {
            return;
        }

        // Extract original exception from the response (Laravel 11+ stores it here)
        $exception = $this->getExceptionFromResponse($response);
        if (! $exception instanceof \Throwable) {
            return;
        }

        // Determine status code — default to 500 for Throwables without one
        $statusCode = $this->getStatusCodeFromException($exception) ?? 500;

        // Only capture server errors (5xx)
        if ($statusCode < 500) {
            return;
        }

        try {
            $this->exceptionTelemetry->capture($exception, $statusCode, $request);
        } catch (\Throwable $e) {
            if (config('watchtower.debug', false)) {
                logger()->debug('Watchtower exception capture failed in terminate: ' . $e->getMessage());
            }
        }
    }

    /**
     * Extract the original exception from the response object.
     *
     * Laravel 11+ attaches the exception to the BinaryFileResponse or
     * the Response object after catching it in the kernel.
     */
    protected function getExceptionFromResponse(Response $response): ?\Throwable
    {
        // Primary method: use getException() if available
        if (method_exists($response, 'getException')) {
            $exc = $response->getException();
            if ($exc instanceof \Throwable) {
                return $exc;
            }
        }

        // Fallback: access via reflection (Laravel internals)
        $refl = new \ReflectionClass($response);
        if ($refl->hasProperty('exception')) {
            $prop = $refl->getProperty('exception');
            $prop->setAccessible(true);
            $exc = $prop->getValue($response);
            if ($exc instanceof \Throwable) {
                return $exc;
            }
        }

        return null;
    }

    /**
     * Get HTTP status code from an exception if available.
     */
    protected function getStatusCodeFromException(\Throwable $exception): ?int
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
     * Set a callback to be notified when request ID is set.
     *
     * @param  callable(?string)  $callback
     */
    public function setRequestIdCallback(callable $callback): void
    {
        $this->requestIdCallback = $callback;
    }

    /**
     * Determine if the request should be skipped.
     */
    protected function shouldSkip(Request $request): bool
    {
        $skipPatterns = config('watchtower.skip_patterns', []);

        foreach ($skipPatterns as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        // Skip the Watchtower API endpoint itself (prevent recursion)
        if ($request->is('api/agent/*')) {
            return true;
        }

        // Skip if this request is going to Watchtower (prevent recursion)
        $watchtowerUrl = config('watchtower.url');
        if ($watchtowerUrl) {
            $watchtowerHost = parse_url($watchtowerUrl, PHP_URL_HOST);
            if ($watchtowerHost && $request->getHost() === $watchtowerHost) {
                return true;
            }
        }

        return false;
    }
}
