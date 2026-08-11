<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Contracts;

interface WatchtowerClientInterface
{
    /**
     * Verify the connection to Watchtower.
     *
     * @return array{success: bool, project?: array{id: string, name: string}, message?: string}
     */
    public function verifyConnection(): array;

    /**
     * Send a heartbeat to Watchtower.
     *
     * @param array<string, mixed> $data
     * @return array{success: bool}
     */
    public function sendHeartbeat(array $data): array;

    /**
     * Send request telemetry to Watchtower.
     *
     * @param array<string, mixed> $data
     * @return array{success: bool}
     */
    public function sendRequestTelemetry(array $data): array;

    /**
     * Send exception telemetry to Watchtower.
     *
     * @param array<string, mixed> $data
     * @param int $timeout
     * @return array{success: bool}
     */
    public function sendExceptionTelemetry(array $data, int $timeout = 3): array;

    /**
     * Send query telemetry to Watchtower.
     *
     * @param array<string, mixed> $data
     * @param int $timeout
     * @return array{success: bool}
     */
    public function sendQueryTelemetry(array $data, int $timeout = 3): array;

    /**
     * Check if the agent is configured properly.
     */
    public function isConfigured(): bool;
}
