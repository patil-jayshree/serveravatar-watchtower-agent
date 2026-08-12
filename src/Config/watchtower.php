<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Watchtower URL
    |--------------------------------------------------------------------------
    |
    | The base URL of your Watchtower installation.
    |
    */
    'url' => env('WATCHTOWER_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Agent Token
    |--------------------------------------------------------------------------
    |
    | Your project agent token. This token is used to authenticate your
    | Laravel application with Watchtower.
    |
    */
    'token' => env('WATCHTOWER_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Agent Version
    |--------------------------------------------------------------------------
    |
    | The current version of the Watchtower Agent package.
    |
    */
    'agent_version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Connection Settings
    |--------------------------------------------------------------------------
    |
    | Configure connection behavior and timeouts.
    |
    */
    'connection' => [
        'timeout' => (int) env('WATCHTOWER_TIMEOUT', 30),
        'connect_timeout' => (int) env('WATCHTOWER_CONNECT_TIMEOUT', 10),
        'retry_enabled' => (bool) env('WATCHTOWER_RETRY_ENABLED', true),
        'retry_attempts' => (int) env('WATCHTOWER_RETRY_ATTEMPTS', 3),
        'retry_delay' => (int) env('WATCHTOWER_RETRY_DELAY', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable the Watchtower Agent. When disabled, the agent
    | will not send any data to Watchtower.
    |
    */
    'enabled' => (bool) env('WATCHTOWER_AGENT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Request Telemetry
    |--------------------------------------------------------------------------
    |
    | Configure request monitoring behavior.
    |
    */
    'request_telemetry' => [
        // Enable or disable request telemetry
        'enabled' => (bool) env('WATCHTOWER_TELEMETRY_ENABLED', true),

        // Slow request threshold in milliseconds
        // Requests at or above this threshold are flagged as slow in Performance dashboard
        'slow_request_threshold' => (int) env('WATCHTOWER_SLOW_REQUEST_THRESHOLD', 1000),

        // Skip telemetry for these paths (Ant-style patterns)
        'skip_patterns' => [
            'telescope/*',
            'horizon/*',
            'api/agent/*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exception Telemetry
    |--------------------------------------------------------------------------
    |
    | Configure exception monitoring behavior.
    |
    */
    'exceptions' => [
        // Enable or disable exception telemetry
        'enabled' => (bool) env('WATCHTOWER_EXCEPTIONS_ENABLED', true),

        // Timeout for exception telemetry requests (seconds)
        'timeout' => (int) env('WATCHTOWER_EXCEPTIONS_TIMEOUT', 3),

        // Capture HTTP 4xx/5xx errors as exceptions
        'capture_http_errors' => (bool) env('WATCHTOWER_CAPTURE_HTTP_ERRORS', false),

        // Skip exceptions from these paths
        'skip_patterns' => [
            'telescope/*',
            'horizon/*',
            'api/agent/*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Monitoring
    |--------------------------------------------------------------------------
    |
    | Configure database query monitoring behavior.
    |
    */
    'query_monitoring' => [
        // Enable or disable query monitoring
        'enabled' => (bool) env('WATCHTOWER_QUERY_MONITORING', false),

        // Slow query threshold in milliseconds
        // Queries at or above this threshold are marked as slow
        'slow_query_threshold' => (int) env('WATCHTOWER_SLOW_QUERY_THRESHOLD', 500),

        // Timeout for query telemetry requests (seconds)
        'timeout' => (int) env('WATCHTOWER_QUERY_TIMEOUT', 3),

        // Connections to ignore (empty = monitor all)
        'ignored_connections' => explode(',', env('WATCHTOWER_QUERY_IGNORED_CONNECTIONS', '')),

        // Minimum duration to capture (ms) - helps reduce noise
        'min_duration' => (int) env('WATCHTOWER_QUERY_MIN_DURATION', 0),

        // Skip queries matching these patterns
        'skip_patterns' => [
            // Example: '#SELECT\s+1#i',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Monitoring
    |--------------------------------------------------------------------------
    |
    | Configure Laravel queue/job monitoring behavior.
    |
    */
    'queue_monitoring' => [
        // Enable or disable queue monitoring
        'enabled' => (bool) env('WATCHTOWER_QUEUE_MONITORING', false),

        // Timeout for job telemetry requests (seconds)
        'timeout' => (int) env('WATCHTOWER_QUEUE_TIMEOUT', 3),

        // Jobs to ignore (Ant-style patterns)
        'ignored_jobs' => [
            // Example: 'App\Jobs\LowPriorityJob',
        ],

        // Queues to ignore (Ant-style patterns)
        'ignored_queues' => [
            // Example: 'low_priority',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Monitoring
    |--------------------------------------------------------------------------
    |
    | Configure Laravel log monitoring behavior.
    |
    */
    'log_monitoring' => [
        // Enable or disable log monitoring
        'enabled' => (bool) env('WATCHTOWER_LOG_MONITORING', false),

        // Minimum log level to capture (DEBUG, INFO, NOTICE, WARNING, ERROR, CRITICAL, ALERT, EMERGENCY)
        'min_level' => strtoupper(env('WATCHTOWER_LOG_MIN_LEVEL', 'DEBUG')),

        // Timeout for log telemetry requests (seconds)
        'timeout' => (int) env('WATCHTOWER_LOG_TIMEOUT', 3),

        // Channels to ignore (empty = capture all)
        'ignored_channels' => array_filter(explode(',', env('WATCHTOWER_LOG_IGNORED_CHANNELS', ''))),

        // Sensitive context keys to redact
        // These keys will have their values replaced with [REDACTED]
        'sensitive_keys' => array_filter(explode(',', env('WATCHTOWER_LOG_SENSITIVE_KEYS',
            'password,token,api_key,secret,authorization,cookie,session,apiKey,client_secret'))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Command Monitoring
    |--------------------------------------------------------------------------
    |
    | Configure Artisan command monitoring behavior.
    |
    */
    'command_monitoring' => [
        // Enable or disable command monitoring
        'enabled' => (bool) env('WATCHTOWER_COMMAND_MONITORING', false),

        // Slow command threshold in milliseconds
        // Commands at or above this threshold are flagged as slow
        'slow_threshold_ms' => (int) env('WATCHTOWER_SLOW_COMMAND_THRESHOLD', 1000),

        // Timeout for command telemetry requests (seconds)
        'timeout' => (int) env('WATCHTOWER_COMMAND_TIMEOUT', 3),

        // Commands to ignore (Ant-style patterns or exact name)
        'ignored_commands' => [
            // Example: 'inspire',
        ],

        // Sensitive option names that should be redacted
        'sensitive_options' => [
            // Additional to defaults: password, token, secret, api-key, etc.
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler Monitoring
    |--------------------------------------------------------------------------
    |
    | Configure Laravel scheduler monitoring behavior.
    |
    */
    'scheduler_monitoring' => [
        // Enable or disable scheduler monitoring
        'enabled' => (bool) env('WATCHTOWER_SCHEDULER_MONITORING', false),

        // Grace period in minutes before marking a task as missed
        // Task won't be marked missed until this period after expected time
        'grace_period_minutes' => (int) env('WATCHTOWER_SCHEDULER_GRACE_PERIOD', 10),

        // Timeout for scheduler telemetry requests (seconds)
        'timeout' => (int) env('WATCHTOWER_SCHEDULER_TIMEOUT', 3),

        // Tasks to ignore (Ant-style patterns or exact name)
        'ignored_tasks' => [
            // Example: 'schedule:list',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | The application environment being monitored.
    |
    */
    'environment' => env('WATCHTOWER_ENVIRONMENT', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | Enable debug mode to log telemetry failures. Do not enable in production.
    |
    */
    'debug' => (bool) env('WATCHTOWER_DEBUG', false),
];
