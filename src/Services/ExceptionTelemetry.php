<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ServerAvatar\Watchtower\Contracts\WatchtowerClientInterface;
use ServerAvatar\Watchtower\Data\ExceptionData;
use Throwable;

class ExceptionTelemetry
{
    /**
     * Request ID from the current request context.
     */
    protected ?string $currentRequestId = null;

    public function __construct(
        protected ExceptionSanitizer $sanitizer,
        protected WatchtowerClientInterface $client,
    ) {}

    /**
     * Capture an exception and send telemetry.
     */
    public function capture(
        Throwable $exception,
        ?int $statusCode = null,
        ?Request $request = null
    ): void {
        // If no request provided, try to get current request
        $request ??= request();

        // Get request context
        $requestId = $this->getCurrentRequestId();
        $method = $request->method();
        $path = $request->getPathInfo();
        $routeName = $this->getRouteName($request);
        $controllerAction = $this->getControllerAction($request);

        // Build exception data using fromThrowable (captures class, function, and structured stack trace)
        $exceptionData = ExceptionData::fromThrowable(
            $exception,
            $requestId,
            $statusCode,
            $method,
            $path,
            $routeName,
            $controllerAction,
        );

        // Convert to array and sanitize
        $data = $exceptionData->toArray();

        // Send non-blocking
        $this->sendTelemetry($data);
    }

    /**
     * Set the current request ID from the request context.
     */
    public function setCurrentRequestId(?string $requestId): void
    {
        $this->currentRequestId = $requestId;
    }

    /**
     * Get the current request ID.
     */
    public function getCurrentRequestId(): ?string
    {
        return $this->currentRequestId;
    }

    /**
     * Send exception telemetry to Watchtower.
     */
    public function sendTelemetry(array $data): void
    {
        // Non-blocking send with short timeout
        $timeout = (int) config('watchtower.exceptions.timeout', 3);

        try {
            $response = $this->client->sendExceptionTelemetry($data, $timeout);

            // Log success in debug mode
            if (config('watchtower.debug', false)) {
                logger()->info('Exception telemetry sent', [
                    'exception_type' => $data['exception_type'],
                    'response' => $response,
                ]);
            }
        } catch (\Throwable $e) {
            // Never let telemetry errors affect the application
            if (config('watchtower.debug', false)) {
                logger()->warning('Failed to send exception telemetry', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get the route name from the request.
     */
    protected function getRouteName(Request $request): ?string
    {
        try {
            return $request->route()?->getName();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get the controller action from the request.
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
