<?php

namespace ServerAvatar\Watchtower\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ServerAvatar\Watchtower\Services\RequestTelemetry;
use Symfony\Component\HttpFoundation\Response;

class WatchtowerRequestTelemetry
{
    public function __construct(
        protected RequestTelemetry $telemetry
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip telemetry if disabled
        if (! config('watchtower.enabled', true) || ! config('watchtower.request_telemetry.enabled', true)) {
            return $next($request);
        }

        // Skip certain paths
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        // Start tracking
        $this->telemetry->start($request);

        // Get the response
        $response = $next($request);

        // End tracking and send telemetry
        $this->telemetry->end($request, $response);

        return $response;
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

        // Skip if this request is going to Watchtower (prevent recursion when sending telemetry)
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
