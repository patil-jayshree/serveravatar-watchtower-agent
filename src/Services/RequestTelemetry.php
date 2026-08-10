<?php

namespace ServerAvatar\Watchtower\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestTelemetry
{
    protected ?string $requestId = null;
    protected ?float $startTime = null;

    public function __construct(
        protected RequestSanitizer $sanitizer,
        protected WatchtowerClient $client,
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
            contentType: $request->getContentType(),
            requestedAt: now()->toIso8601String(),
        );

        // Send non-blocking
        $this->sendTelemetry($data->toArray());
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
