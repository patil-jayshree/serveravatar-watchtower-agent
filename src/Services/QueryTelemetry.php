<?php

namespace ServerAvatar\Watchtower\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ServerAvatar\Watchtower\Contracts\WatchtowerClientInterface;
use ServerAvatar\Watchtower\Data\QueryData;

class QueryTelemetry
{
    /**
     * Whether query monitoring is enabled.
     */
    protected bool $enabled = false;

    /**
     * Slow query threshold in milliseconds.
     */
    protected int $slowQueryThreshold = 500;

    /**
     * Current request ID context.
     */
    protected ?string $currentRequestId = null;

    /**
     * Transaction counter for unique transaction IDs.
     */
    protected int $transactionCounter = 0;

    /**
     * Current transaction ID.
     */
    protected ?string $currentTransactionId = null;

    /**
     * Whether the listener is already registered.
     */
    protected bool $listenerRegistered = false;

    public function __construct(
        protected QuerySanitizer $sanitizer,
        protected WatchtowerClientInterface $client,
    ) {}

    /**
     * Enable query monitoring.
     */
    public function enable(): void
    {
        if (! config('watchtower.enabled', true) || ! config('watchtower.query_monitoring.enabled', true)) {
            return;
        }

        $this->enabled = true;
        $this->slowQueryThreshold = (int) config('watchtower.query_monitoring.slow_query_threshold', 500);
        $this->registerListener();
    }

    /**
     * Disable query monitoring.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Set the current request ID context.
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
     * Register the database query listener.
     */
    protected function registerListener(): void
    {
        if ($this->listenerRegistered) {
            return;
        }

        $self = $this; // For closure

        DB::listen(function ($query) use ($self) {
            if (! $self->enabled) {
                return;
            }

            // Skip if query should be blocked
            if ($self->sanitizer->shouldBlock($query->sql)) {
                return;
            }

            // Skip queries below minimum duration threshold
            $durationMs = $query->time;
            $minDuration = (int) config('watchtower.query_monitoring.min_duration', 0);
            if ($minDuration > 0 && $durationMs < $minDuration) {
                return;
            }

            $self->captureQuery(
                sql: $query->sql,
                bindings: $query->bindings,
                durationMs: $durationMs,
                connectionName: $query->connectionName ?? 'default'
            );
        });

        $this->listenerRegistered = true;
    }

    /**
     * Capture and send a database query.
     */
    public function captureQuery(
        string $sql,
        array $bindings,
        float $durationMs,
        string $connectionName = 'default'
    ): void {
        // Determine driver
        $driver = $this->getDriver($connectionName);

        // Get database name safely
        $databaseName = $this->getDatabaseName($connectionName);

        // Build query data
        $queryData = new QueryData(
            sql: $sql,
            bindings: $bindings,
            durationMs: $durationMs,
            requestId: $this->currentRequestId,
            connectionName: $connectionName,
            driver: $driver,
            databaseName: $databaseName,
            transactionId: $this->currentTransactionId,
            occurredAt: now()->toIso8601String(),
        );

        // Sanitize and send
        $sanitizedData = $this->sanitizer->sanitize($queryData->toArray());
        $this->sendTelemetry($sanitizedData);
    }

    /**
     * Get the database driver for a connection.
     */
    protected function getDriver(string $connectionName): ?string
    {
        try {
            $connection = DB::connection($connectionName);
            $driver = $connection->getDriverName();

            return strtolower($driver);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get the database name for a connection.
     */
    protected function getDatabaseName(string $connectionName): ?string
    {
        try {
            $connection = DB::connection($connectionName);
            $databaseName = $connection->getDatabaseName();

            // Don't expose default/database names that might be sensitive
            if (in_array($databaseName, ['default', 'database', 'main'])) {
                return null;
            }

            return $databaseName;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Send telemetry to Watchtower with graceful failure.
     */
    protected function sendTelemetry(array $data): void
    {
        $timeout = (int) config('watchtower.query_monitoring.timeout', 3);

        try {
            $this->client->sendQueryTelemetry($data, $timeout);
        } catch (\Throwable $e) {
            // Never let telemetry errors affect the application
            if (config('watchtower.debug', false)) {
                logger()->debug('Watchtower query telemetry failed', [
                    'error' => $e->getMessage(),
                    'sql' => substr($data['sql'], 0, 100),
                ]);
            }
        }
    }

    /**
     * Begin a pseudo-transaction context for tracking.
     */
    public function beginTransaction(): void
    {
        $this->transactionCounter++;
        $this->currentTransactionId = 'txn_' . $this->transactionCounter . '_' . Str::random(8);
    }

    /**
     * Commit the current transaction context.
     */
    public function commitTransaction(): void
    {
        $this->currentTransactionId = null;
    }

    /**
     * Rollback the current transaction context.
     */
    public function rollbackTransaction(): void
    {
        $this->currentTransactionId = null;
    }
}
