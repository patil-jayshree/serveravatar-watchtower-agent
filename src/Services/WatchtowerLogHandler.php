<?php

namespace ServerAvatar\Watchtower\Services;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class WatchtowerLogHandler extends AbstractProcessingHandler
{
    protected LogTelemetry $logTelemetry;

    public function __construct(
        LogTelemetry $logTelemetry,
        Level $level = Level::Debug,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
        $this->logTelemetry = $logTelemetry;
    }

    /**
     * Writes the log record to Watchtower.
     */
    protected function write(LogRecord $record): void
    {
        // Delegate to LogTelemetry - it handles all the logic including
        // enabled check, client check, and error swallowing
        try {
            $this->logTelemetry->processLog($record);
        } catch (\Throwable $e) {
            // Never let telemetry failures affect Laravel logging
        }
    }
}
