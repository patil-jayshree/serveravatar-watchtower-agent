<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Installer;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class WatchtowerInstaller
{
    /**
     * Default configuration values for all monitoring features.
     * These are only used if the user hasn't already set values in .env.
     *
     * @var array<string, string|bool>
     */
    protected array $defaults = [
        // Core
        'WATCHTOWER_AGENT_ENABLED' => 'true',
        'WATCHTOWER_URL' => '',

        // Request & Exception Telemetry
        'WATCHTOWER_TELEMETRY_ENABLED' => 'true',
        'WATCHTOWER_EXCEPTIONS_ENABLED' => 'true',
        'WATCHTOWER_CAPTURE_HTTP_ERRORS' => 'true',
        'WATCHTOWER_SLOW_REQUEST_THRESHOLD' => '1000',

        // Query Monitoring
        'WATCHTOWER_QUERY_MONITORING' => 'true',
        'WATCHTOWER_SLOW_QUERY_THRESHOLD' => '100',

        // Command Monitoring
        'WATCHTOWER_COMMAND_MONITORING' => 'true',
        'WATCHTOWER_SLOW_COMMAND_THRESHOLD' => '1000',

        // Queue Monitoring
        'WATCHTOWER_QUEUE_MONITORING' => 'true',

        // Log Monitoring
        'WATCHTOWER_LOG_MONITORING' => 'true',

        // Scheduler Monitoring
        'WATCHTOWER_SCHEDULER_MONITORING' => 'true',
        'WATCHTOWER_SCHEDULER_GRACE_PERIOD' => '10',
    ];

    /**
     * Keys that require a token (should not be set without token validation).
     */
    protected array $tokenRequiredKeys = [
        'WATCHTOWER_TELEMETRY_ENABLED',
        'WATCHTOWER_EXCEPTIONS_ENABLED',
        'WATCHTOWER_CAPTURE_HTTP_ERRORS',
        'WATCHTOWER_QUERY_MONITORING',
        'WATCHTOWER_COMMAND_MONITORING',
        'WATCHTOWER_QUEUE_MONITORING',
        'WATCHTOWER_LOG_MONITORING',
        'WATCHTOWER_SCHEDULER_MONITORING',
    ];

    /**
     * Environment variable patterns that are considered sensitive.
     */
    protected array $sensitivePatterns = [
        'TOKEN',
        'SECRET',
        'PASSWORD',
        'KEY',
        'API_',
    ];

    /**
     * HTTP client for testing connections.
     */
    protected ?Client $httpClient = null;

    /**
     * Installation result.
     *
     * @var array{success: bool, message: string, configured: array<string, string|bool>, skipped: array<string, string>}
     */
    protected array $result = [
        'success' => false,
        'message' => '',
        'configured' => [],
        'skipped' => [],
    ];

    /**
     * Run the installer.
     *
     * @param  string  $url  Watchtower base URL
     * @param  string  $token  Agent token
     * @param  string|null  $envFile  Path to .env file (defaults to app's .env)
     * @return array{success: bool, message: string, configured: array<string, string|bool>, skipped: array<string, string>}
     */
    public function install(string $url, string $token, ?string $envFile = null): array
    {
        $this->result = [
            'success' => false,
            'message' => '',
            'configured' => [],
            'skipped' => [],
        ];

        $envFile = $envFile ?? base_path('.env');

        // Validate URL
        $url = $this->normalizeUrl($url);
        if (empty($url)) {
            $this->result['message'] = 'Watchtower URL is required.';
            return $this->result;
        }

        // Validate token
        $token = trim($token);
        if (empty($token)) {
            $this->result['message'] = 'Agent token is required.';
            return $this->result;
        }

        // Test connection
        $connectionTest = $this->testConnection($url, $token);
        if (! $connectionTest['success']) {
            $this->result['message'] = $connectionTest['message'];
            return $this->result;
        }

        // Read existing .env
        $envContents = $this->readEnvFile($envFile);
        $existingVars = $this->parseEnvContents($envContents);

        // Build configuration updates
        $updates = [];

        // URL - always set
        $updates['WATCHTOWER_URL'] = $url;

        // Token - always set
        $updates['WATCHTOWER_TOKEN'] = $token;

        // All other keys - only set if not already defined
        foreach ($this->defaults as $key => $defaultValue) {
            if ($key === 'WATCHTOWER_URL' || $key === 'WATCHTOWER_TOKEN') {
                continue; // Already handled above
            }

            $envKey = $this->resolveEnvKey($key, $existingVars);

            if ($envKey !== null) {
                // Key exists in .env (with or without value)
                $this->result['skipped'][$key] = 'Already configured';
            } else {
                // Key doesn't exist - use default
                $updates[$key] = $defaultValue;
                $this->result['configured'][$key] = $defaultValue;
            }
        }

        // Write updated .env
        if (! empty($updates)) {
            $this->writeEnvFile($envFile, $envContents, $updates);
        }

        // Clear config cache
        $this->clearConfigCache();

        $this->result['success'] = true;
        $this->result['message'] = 'Installation completed successfully.';

        return $this->result;
    }

    /**
     * Validate a token against a Watchtower instance without modifying .env.
     *
     * @param  string  $url  Watchtower base URL
     * @param  string  $token  Agent token
     * @return array{success: bool, message: string, project?: array{name: string, id: int}}
     */
    public function validateToken(string $url, string $token): array
    {
        $url = $this->normalizeUrl($url);

        if (empty($url)) {
            return ['success' => false, 'message' => 'Watchtower URL is required.'];
        }

        if (empty(trim($token))) {
            return ['success' => false, 'message' => 'Agent token is required.'];
        }

        return $this->testConnection($url, $token);
    }

    /**
     * Test connection to Watchtower with a given URL and token.
     *
     * @return array{success: bool, message: string, project?: array{name: string, id: int}}
     */
    protected function testConnection(string $url, string $token): array
    {
        try {
            $client = $this->getHttpClient($url, $token);

            $response = $client->post('/api/agent/connection', [
                'json' => ['token' => $token],
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if ($response->getStatusCode() === 200 && ! empty($body['project'])) {
                return [
                    'success' => true,
                    'message' => 'Connected successfully.',
                    'project' => [
                        'name' => $body['project']['name'] ?? 'Unknown Project',
                        'id' => $body['project']['id'] ?? null,
                    ],
                ];
            }

            if ($response->getStatusCode() === 401) {
                return ['success' => false, 'message' => 'Invalid or expired token.'];
            }

            if ($response->getStatusCode() === 403) {
                return ['success' => false, 'message' => 'Access denied. Token may be revoked.'];
            }

            return ['success' => false, 'message' => 'Unexpected response from Watchtower.'];

        } catch (GuzzleException $e) {
            $code = $e->getCode();

            if ($code === 0 || $e instanceof \GuzzleHttp\Exception\ConnectException) {
                return [
                    'success' => false,
                    'message' => 'Could not connect to Watchtower. Please check the URL and your network connection.',
                ];
            }

            if ($code === 401) {
                return ['success' => false, 'message' => 'Invalid or expired token.'];
            }

            if ($code === 403) {
                return ['success' => false, 'message' => 'Access denied. Token may be revoked.'];
            }

            if ($code >= 500) {
                return ['success' => false, 'message' => 'Watchtower server error. Please try again later.'];
            }

            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Normalize a URL (add scheme, remove trailing slash).
     */
    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if (empty($url)) {
            return '';
        }

        // Add scheme if missing
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'https://' . $url;
        }

        // Remove trailing slash
        $url = rtrim($url, '/');

        return $url;
    }

    /**
     * Resolve an env key - check if it exists in the parsed .env.
     * Returns the existing value if the key is found, null if not.
     */
    protected function resolveEnvKey(string $key, array $existingVars): ?string
    {
        // Direct match
        if (isset($existingVars[$key])) {
            return $existingVars[$key];
        }

        // Check without prefix (some env vars might use different naming)
        $lowerKey = strtolower($key);

        foreach ($existingVars as $k => $v) {
            if (strtolower($k) === $lowerKey) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Read the .env file contents.
     */
    protected function readEnvFile(string $path): string
    {
        if (! file_exists($path)) {
            // Try .env.example or create a minimal .env
            $examplePath = str_replace('.env', '.env.example', $path);
            if (file_exists($examplePath)) {
                return file_get_contents($examplePath);
            }
            return '';
        }

        return file_get_contents($path);
    }

    /**
     * Parse .env contents into key-value array.
     *
     * @return array<string, string>
     */
    protected function parseEnvContents(string $contents): array
    {
        $result = [];

        $lines = preg_split('/\r\n|\r|\n/', $contents);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Skip array-style exports
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            // Find first = sign
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Remove quotes if present
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Write updates to the .env file.
     *
     * @param  string  $path  .env file path
     * @param  string  $contents  Current .env contents
     * @param  array<string, string>  $updates  Key-value pairs to add/update
     */
    protected function writeEnvFile(string $path, string $contents, array $updates): void
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents);
        $outputLines = [];
        $keysUpdated = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip empty lines at the end
            if ($trimmed === '' && empty($lines)) {
                continue;
            }

            // Check if this line matches any key we're updating
            $updated = false;
            foreach ($updates as $key => $value) {
                $pattern = '/^(\s*export\s+)?' . preg_quote($key, '/') . '\s*=/';
                if (preg_match($pattern, $line)) {
                    // Replace this line with updated value
                    $outputLines[] = $key . '=' . $this->formatEnvValue($value);
                    $keysUpdated[] = $key;
                    $updated = true;
                    break;
                }
            }

            if (! $updated) {
                $outputLines[] = $line;
            }
        }

        // Add any keys that weren't found in existing file
        foreach ($updates as $key => $value) {
            if (! in_array($key, $keysUpdated)) {
                $outputLines[] = '';
                $outputLines[] = '# Watchtower Agent';
                $outputLines[] = $key . '=' . $this->formatEnvValue($value);
            }
        }

        // Remove trailing empty lines but keep structure
        while (! empty($outputLines) && trim(end($outputLines)) === '') {
            array_pop($outputLines);
        }

        $newContents = implode("\n", $outputLines);

        // Ensure directory exists
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $newContents . "\n");
    }

    /**
     * Format a value for .env file.
     */
    protected function formatEnvValue(string $value): string
    {
        // Boolean values
        if (strtolower($value) === 'true') {
            return 'true';
        }
        if (strtolower($value) === 'false') {
            return 'false';
        }

        // Numeric values don't need quotes
        if (is_numeric($value)) {
            return $value;
        }

        // Check if value contains spaces, quotes, or special characters
        $needsQuotes = preg_match('#^[\w\-\./:]+$#', $value) !== 1;
        if ($needsQuotes) {
            // Use double quotes, escape internal double quotes
            return '"' . str_replace('"', '\\"', $value) . '"';
        }

        return $value;
    }

    /**
     * Clear Laravel's config cache.
     */
    protected function clearConfigCache(): void
    {
        // Use Laravel's config cache clear if available
        if (function_exists('artisan')) {
            try {
                \Illuminate\Support\Facades\Artisan::call('config:clear');
            } catch (\Throwable) {
                // Ignore errors during cache clearing
            }
        }

        // Also try direct file removal
        try {
            $bootstrapPath = base_path('bootstrap');
            $cacheFile = $bootstrapPath . '/cache/config.php';
            if (file_exists($cacheFile)) {
                @unlink($cacheFile);
            }
        } catch (\Throwable) {
            // Ignore errors during cache clearing
        }
    }

    /**
     * Get HTTP client for connection testing.
     */
    protected function getHttpClient(string $url, string $token): Client
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client([
                'base_uri' => $url,
                'timeout' => 15,
                'connect_timeout' => 5,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'WatchtowerAgent/Installer',
                    'X-Agent-Token' => $token,
                ],
            ]);
        }

        return $this->httpClient;
    }

    /**
     * Check if a key name looks sensitive (for logging purposes).
     */
    public function isSensitiveKey(string $key): bool
    {
        foreach ($this->sensitivePatterns as $pattern) {
            if (stripos($key, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the list of keys that will be configured (not already set).
     *
     * @return array<string, string|bool>
     */
    public function getDefaultConfiguration(): array
    {
        return array_filter($this->defaults, fn($key) => $key !== 'WATCHTOWER_URL' && $key !== 'WATCHTOWER_TOKEN', ARRAY_FILTER_USE_KEY);
    }
}
