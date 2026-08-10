<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use ServerAvatar\Watchtower\Contracts\WatchtowerClientInterface;

class WatchtowerClient implements WatchtowerClientInterface
{
    private ?Client $httpClient = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly array $connectionSettings,
        private readonly string $agentVersion
    ) {}

    /**
     * Get the HTTP client instance.
     */
    protected function getHttpClient(): Client
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client([
                'base_uri' => $this->baseUrl,
                'timeout' => $this->connectionSettings['timeout'] ?? 30,
                'connect_timeout' => $this->connectionSettings['connect_timeout'] ?? 10,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'WatchtowerAgent/' . $this->agentVersion,
                    'X-Agent-Token' => $this->token,
                ],
            ]);
        }

        return $this->httpClient;
    }

    /**
     * {@inheritdoc}
     */
    public function isConfigured(): bool
    {
        return ! empty($this->baseUrl) && ! empty($this->token);
    }

    /**
     * {@inheritdoc}
     */
    public function verifyConnection(): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Watchtower is not configured. Please set WATCHTOWER_URL and WATCHTOWER_TOKEN.',
            ];
        }

        try {
            $response = $this->makeRequest('POST', '/api/agent/connection', [
                'token' => $this->token,
            ]);

            return [
                'success' => true,
                'project' => $response['project'] ?? null,
            ];
        } catch (GuzzleException $e) {
            Log::debug('Watchtower connection verification failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $this->getErrorMessage($e),
            ];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function sendRequestTelemetry(array $data): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false];
        }

        try {
            $payload = array_merge($data, [
                'token' => $this->token,
            ]);

            $response = $this->makeRequest('POST', '/api/agent/requests', $payload);

            return [
                'success' => true,
                'response' => $response,
            ];
        } catch (GuzzleException $e) {
            // Fail silently for telemetry
            Log::debug('Watchtower request telemetry failed', [
                'error' => $e->getMessage(),
            ]);

            return ['success' => false];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function sendHeartbeat(array $data): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false];
        }

        try {
            $payload = array_merge($data, [
                'agent_version' => $this->agentVersion,
                'timestamp' => now()->toIso8601String(),
            ]);

            $response = $this->makeRequest('POST', '/api/agent/heartbeat', $payload);

            return [
                'success' => true,
                'response' => $response,
            ];
        } catch (GuzzleException $e) {
            Log::debug('Watchtower heartbeat failed', [
                'error' => $e->getMessage(),
            ]);

            return ['success' => false];
        }
    }

    /**
     * Make an HTTP request with retry logic.
     *
     * @throws GuzzleException
     */
    protected function makeRequest(string $method, string $uri, array $data): array
    {
        $client = $this->getHttpClient();
        $retryAttempts = $this->connectionSettings['retry_enabled'] ?? false
            ? ($this->connectionSettings['retry_attempts'] ?? 1)
            : 1;
        $retryDelay = $this->connectionSettings['retry_delay'] ?? 1000;
        $lastException = null;

        for ($attempt = 1; $attempt <= $retryAttempts; $attempt++) {
            try {
                $response = $client->request($method, $uri, [
                    'json' => $data,
                ]);

                return json_decode($response->getBody()->getContents(), true) ?? [];
            } catch (ConnectException $e) {
                $lastException = $e;

                // Don't retry on connection failure
                if ($attempt >= $retryAttempts) {
                    break;
                }

                usleep($retryDelay * 1000);
            } catch (GuzzleException $e) {
                $lastException = $e;

                // Don't retry on server errors (5xx)
                if ($e->getCode() >= 500 && $attempt < $retryAttempts) {
                    usleep($retryDelay * 1000);
                    continue;
                }

                break;
            }
        }

        throw $lastException;
    }

    /**
     * Get a user-friendly error message.
     */
    protected function getErrorMessage(GuzzleException $e): string
    {
        if ($e instanceof ConnectException) {
            return 'Could not connect to Watchtower. Please check your network connection and WATCHTOWER_URL.';
        }

        $statusCode = $e->getCode();

        if ($statusCode === 401) {
            return 'Invalid or expired Watchtower token. Please check your WATCHTOWER_TOKEN.';
        }

        if ($statusCode === 403) {
            return 'Access denied. Please check your Watchtower token permissions.';
        }

        if ($statusCode >= 500) {
            return 'Watchtower server error. Please try again later.';
        }

        return 'An error occurred while connecting to Watchtower: ' . $e->getMessage();
    }
}
