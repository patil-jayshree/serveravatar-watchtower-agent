<?php

namespace ServerAvatar\Watchtower\Services;

use Illuminate\Support\Str;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use ServerAvatar\Watchtower\Contracts\WatchtowerClientInterface;
use ServerAvatar\Watchtower\Log\LogData;

class LogTelemetry
{
    protected ?WatchtowerClientInterface $client = null;

    protected LogSanitizer $sanitizer;

    protected bool $enabled = false;

    public function __construct(
        LogSanitizer $sanitizer
    ) {
        $this->sanitizer = $sanitizer;
        $this->enabled = (bool) config('watchtower.log_monitoring.enabled', false);
    }

    /**
     * Create a Monolog handler for Watchtower log telemetry.
     */
    public function createHandler(): WatchtowerLogHandler
    {
        $level = $this->getLogLevel();
        $handler = new WatchtowerLogHandler($this, $level);

        return $handler;
    }

    /**
     * Process a log record and send to Watchtower.
     */
    public function processLog(LogRecord $record): void
    {
        if (!$this->enabled) {
            return;
        }

        // Don't process if no client is set (agent not connected)
        if ($this->client === null) {
            return;
        }

        // Skip if Watchtower URL is not configured
        $watchtowerUrl = config('watchtower.url');
        if (empty($watchtowerUrl)) {
            return;
        }

        // Check if this channel should be ignored
        $ignoredChannels = config('watchtower.log_monitoring.ignored_channels', []);
        if (!empty($ignoredChannels) && in_array($record->channel, $ignoredChannels, true)) {
            return;
        }

        try {
            $logData = $this->buildLogData($record);
            $this->client->sendLogTelemetry($logData->toArray());
        } catch (\Throwable $e) {
            // Never let telemetry failures affect Laravel logging
            // Just swallow the exception silently
        }
    }

    /**
     * Build LogData from a Monolog record.
     */
    protected function buildLogData(LogRecord $record): LogData
    {
        $context = $record->context;
        $extra = $record->extra;

        // Sanitize context
        $sanitizedContext = $this->sanitizer->sanitize($context);

        // Extract exception info from context if present
        $exceptionClass = null;
        $exceptionMessage = null;
        $file = null;
        $line = null;

        // Access file/line - in Monolog 3.x these are on the record but may require IntrospectionProcessor
        // We also check context as fallback
        if (property_exists($record, 'file') && isset($record->file)) {
            $file = $record->file;
        }
        if (property_exists($record, 'line') && isset($record->line)) {
            $line = $record->line;
        }
        // Fallback to context if not set
        if (!$file && isset($context['file'])) {
            $file = $context['file'];
            $line = $context['line'] ?? null;
        }

        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $exception = $context['exception'];
            $exceptionClass = get_class($exception);
            $exceptionMessage = $exception->getMessage();
            if (empty($file)) {
                $file = $exception->getFile();
            }
            if (empty($line)) {
                $line = $exception->getLine();
            }
        }

        // Request ID from context or extra
        $requestId = $extra['request_id']
            ?? $context['request_id']
            ?? $context['requestId']
            ?? null;

        return new LogData(
            uuid: (string) Str::uuid(),
            level: $this->normalizeLevel($record->level),
            message: $record->message,
            context: $this->filterEmptyContext($sanitizedContext),
            channel: $record->channel,
            requestId: $requestId,
            exceptionClass: $exceptionClass,
            exceptionMessage: $exceptionMessage,
            file: $file,
            line: $line,
            environment: $this->getEnvironment(),
            host: $this->getHost(),
            agentVersion: $this->getAgentVersion(),
            timestamp: $record->datetime,
        );
    }

    /**
     * Normalize Monolog level to string.
     */
    protected function normalizeLevel(Level $level): string
    {
        return match ($level) {
            Level::Debug => 'DEBUG',
            Level::Info => 'INFO',
            Level::Notice => 'NOTICE',
            Level::Warning => 'WARNING',
            Level::Error => 'ERROR',
            Level::Critical => 'CRITICAL',
            Level::Alert => 'ALERT',
            Level::Emergency => 'EMERGENCY',
            default => 'INFO',
        };
    }

    /**
     * Get the minimum log level to capture.
     */
    protected function getLogLevel(): Level
    {
        $minLevel = strtoupper(config('watchtower.log_monitoring.min_level', 'DEBUG'));

        return match ($minLevel) {
            'DEBUG' => Level::Debug,
            'INFO' => Level::Info,
            'NOTICE' => Level::Notice,
            'WARNING' => Level::Warning,
            'ERROR' => Level::Error,
            'CRITICAL' => Level::Critical,
            'ALERT' => Level::Alert,
            'EMERGENCY' => Level::Emergency,
            default => Level::Debug,
        };
    }

    /**
     * Get the environment.
     */
    protected function getEnvironment(): string
    {
        return config('watchtower.environment', config('app.env', 'production'));
    }

    /**
     * Get the host identifier.
     */
    protected function getHost(): string
    {
        return config('watchtower.host', gethostname() ?? 'unknown');
    }

    /**
     * Get the agent version.
     */
    protected function getAgentVersion(): string
    {
        return config('watchtower.agent_version', '1.0.0');
    }

    /**
     * Filter out empty context values to reduce payload size.
     */
    protected function filterEmptyContext(?array $context): ?array
    {
        if (empty($context)) {
            return null;
        }

        // Remove common noise keys
        unset(
            $context['exception'],
            $context['request_id'],
            $context['requestId'],
        );

        if (empty($context)) {
            return null;
        }

        return $context;
    }

    /**
     * Set the Watchtower client.
     */
    public function setClient(?WatchtowerClientInterface $client): void
    {
        $this->client = $client;
    }

    /**
     * Check if monitoring is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Manually send a log entry (for testing or custom logging).
     */
    public function sendLog(
        string $level,
        string $message,
        ?array $context = null,
        ?string $channel = null
    ): void {
        if (!$this->enabled) {
            return;
        }

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: $channel ?? 'watchtower',
            level: $this->parseLevel($level),
            message: $message,
            context: $context ?? [],
            extra: [],
        );

        $this->processLog($record);
    }

    /**
     * Parse a level string to Monolog Level.
     */
    protected function parseLevel(string $level): Level
    {
        return match (strtoupper($level)) {
            'DEBUG' => Level::Debug,
            'INFO' => Level::Info,
            'NOTICE' => Level::Notice,
            'WARNING', 'WARN' => Level::Warning,
            'ERROR' => Level::Error,
            'CRITICAL' => Level::Critical,
            'ALERT' => Level::Alert,
            'EMERGENCY', 'EMERG' => Level::Emergency,
            default => Level::Info,
        };
    }
}
