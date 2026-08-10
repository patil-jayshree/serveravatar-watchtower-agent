<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Services;

use ServerAvatar\Watchtower\Client\WatchtowerClient;

class ConnectionVerifier
{
    public function __construct(
        private readonly WatchtowerClient $client
    ) {}

    /**
     * Verify the connection to Watchtower.
     *
     * @return array{connected: bool, project?: array{name: string}, message?: string}
     */
    public function verify(): array
    {
        if (! config('watchtower.enabled', true)) {
            return [
                'connected' => false,
                'message' => 'Watchtower Agent is disabled.',
            ];
        }

        if (! $this->client->isConfigured()) {
            return [
                'connected' => false,
                'message' => 'Watchtower is not configured. Please set WATCHTOWER_URL and WATCHTOWER_TOKEN.',
            ];
        }

        $result = $this->client->verifyConnection();

        if (! $result['success']) {
            return [
                'connected' => false,
                'message' => $result['message'] ?? 'Connection verification failed.',
            ];
        }

        return [
            'connected' => true,
            'project' => $result['project'] ?? null,
        ];
    }
}
