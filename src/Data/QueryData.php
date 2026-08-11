<?php

namespace ServerAvatar\Watchtower\Data;

class QueryData
{
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        public readonly float $durationMs,
        public readonly ?string $requestId,
        public readonly ?string $connectionName,
        public readonly ?string $driver,
        public readonly ?string $databaseName,
        public readonly ?string $transactionId,
        public readonly string $occurredAt,
    ) {}

    /**
     * Convert to array for transmission.
     */
    public function toArray(): array
    {
        return [
            'sql' => $this->sql,
            'bindings' => $this->bindings,
            'duration_ms' => (int) $this->durationMs,
            'request_id' => $this->requestId,
            'connection_name' => $this->connectionName,
            'driver' => $this->driver,
            'database_name' => $this->databaseName,
            'transaction_id' => $this->transactionId,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
