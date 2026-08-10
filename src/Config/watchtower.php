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
];
